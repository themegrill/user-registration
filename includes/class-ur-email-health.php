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
		 * Transient key for Email Health delivery failure telemetry.
		 *
		 * @var string
		 */
		const FAILURE_TRANSIENT = 'user_registration_email_health_failures';

		/**
		 * Legacy transient key shared with the dismissible admin failure notice.
		 *
		 * @var string
		 */
		const LEGACY_FAILURE_TRANSIENT = 'user_registration_mail_send_failed_count';

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
		 * Admin settings relative URLs for known third-party SMTP plugins.
		 *
		 * @var array<string, string>
		 */
		private static $known_smtp_settings_pages = array(
			'wp-mail-smtp/wp_mail_smtp.php'     => 'admin.php?page=wp-mail-smtp',
			'wp-mail-smtp-pro/wp_mail_smtp.php' => 'admin.php?page=wp-mail-smtp',
			'fluent-smtp/fluent-smtp.php'       => 'options-general.php?page=fluent-mail',
			'post-smtp/postman-smtp.php'        => 'admin.php?page=postman',
			'easy-wp-smtp/easy-wp-smtp.php'     => 'admin.php?page=easy-wp-smtp',
			'smtp-mailer/main.php'              => 'options-general.php?page=smtp-mailer-settings',
			'wp-smtp/wp-smtp.php'               => 'admin.php?page=wp-smtp/wp-smtp.php',
			'mailgun/mailgun.php'               => 'options-general.php?page=mailgun',
			'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'options-general.php?page=sendgrid-settings',
			'wp-ses/wp-ses.php'                 => 'options-general.php?page=wp-offload-ses',
			'gravitysmtp/gravitysmtp.php'       => 'admin.php?page=gravitysmtp-settings',
			'suremails/suremails.php'           => 'options-general.php?page=suremail',
			'yay-smtp/yay-smtp.php'             => 'admin.php?page=yaysmtp',
			'brevo-wp/brevo-wp.php'             => 'admin.php?page=sib_page_home',
			'mailin/sendinblue.php'             => 'admin.php?page=sib_page_home',
		);

		/**
		 * Multi-label public suffixes that must never count as an aligned parent domain.
		 *
		 * @var array<int, string>
		 */
		private static $multi_label_public_suffixes = array(
			'co.uk',
			'org.uk',
			'ac.uk',
			'gov.uk',
			'me.uk',
			'net.uk',
			'com.au',
			'net.au',
			'org.au',
			'edu.au',
			'gov.au',
			'co.nz',
			'org.nz',
			'net.nz',
			'co.in',
			'net.in',
			'org.in',
			'firm.in',
			'gen.in',
			'ind.in',
			'co.jp',
			'or.jp',
			'ne.jp',
			'ac.jp',
			'co.za',
			'org.za',
			'com.br',
			'org.br',
			'net.br',
			'com.mx',
			'com.sg',
			'com.my',
			'com.ph',
			'com.hk',
			'com.tw',
			'com.tr',
			'co.kr',
			'co.id',
			'com.np',
			'org.np',
			'edu.np',
			's3-tastewp.com',
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
		 * Per-request cache for third-party PHPMailer/hook probe results.
		 *
		 * @var array<string, mixed>|null
		 */
		private static $probe_cache = null;

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
			$smtp            = self::detect_smtp( $recent_failures );
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
		 * Record an email delivery failure for Email Health independent of notice dismissals.
		 *
		 * @param string $error_message Failure message captured from wp_mail_failed or fallback.
		 * @param string $source        Failure origin ('test' | 'registration' | 'mail').
		 * @return void
		 */
		public static function record_failure( $error_message, $source = 'mail' ) {
			$clean_error = self::clean_failure_message( $error_message );
			$provider    = self::get_active_provider_slug();
			$existing    = get_transient( self::FAILURE_TRANSIENT );
			$count       = 0;

			if ( is_array( $existing ) && isset( $existing['failed_count'] ) ) {
				$prev_provider = isset( $existing['provider'] ) ? (string) $existing['provider'] : '';
				if ( '' === $prev_provider || $prev_provider === $provider ) {
					$count = (int) $existing['failed_count'];
				}
			}

			++$count;

			set_transient(
				self::FAILURE_TRANSIENT,
				array(
					'failed_count'  => $count,
					'error_message' => $clean_error,
					'source'        => sanitize_key( (string) $source ),
					'provider'      => $provider,
					'recorded_at'   => time(),
				),
				WEEK_IN_SECONDS
			);
		}

		/**
		 * Clear stored Email Health and legacy mail failure telemetry.
		 *
		 * @return void
		 */
		public static function clear_failures() {
			delete_transient( self::FAILURE_TRANSIENT );
			delete_transient( self::LEGACY_FAILURE_TRANSIENT );
		}

		/**
		 * Reset the per-request third-party probe cache.
		 *
		 * @return void
		 */
		public static function reset_probe_cache() {
			self::$probe_cache = null;
		}

		/**
		 * Compute the sidebar indicator state for Email Health using only lightweight options and transients.
		 *
		 * @param string $incoming_issue Optional incoming issue context.
		 * @return string 'critical' | 'warning' | ''
		 */
		public static function get_sidebar_dot_status( $incoming_issue = '' ) {
			$incoming_issue = self::sanitize_incoming_issue( $incoming_issue );
			$has_warning    = false;

			if ( ur_option_checked( 'user_registration_email_setting_disable_email' ) ) {
				return 'critical';
			}

			if ( ! ur_option_checked( 'user_registration_enable_email_confirmation', true ) ) {
				$confirmation = self::get_email_confirmation_info();
				if ( ! empty( $confirmation['form_requires_confirmation'] ) ) {
					return 'critical';
				}
				$has_warning = true;
			}

			$recent_failures = self::get_recent_failures();
			if ( (int) $recent_failures['count'] > 0 || '' !== $recent_failures['last_error'] || 'failed' === $incoming_issue ) {
				return 'critical';
			}

			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$active_known = self::get_active_known_smtp_plugins();

			if ( is_plugin_active( 'smart-smtp/smart-smtp.php' ) ) {
				$smart = self::inspect_smart_smtp( $recent_failures, '', '', false, false );
				if ( 'token-expired' === $smart['status'] ) {
					return 'critical';
				}
				if ( 'unconfigured' === $smart['status'] || ! empty( $active_known ) ) {
					$has_warning = true;
				}
			} elseif ( ! empty( $active_known ) ) {
				$primary = self::select_primary_known_smtp( $active_known );
				if ( ! $primary['configured'] || count( $active_known ) > 1 ) {
					$has_warning = true;
				}
			}

			if ( $has_warning || '' !== $incoming_issue ) {
				return 'warning';
			}

			return '';
		}

		/**
		 * Detect active SMTP / transactional mail transport configuration.
		 *
		 * @param array|string $recent_failures Recent failure telemetry array (or legacy error string).
		 * @return array<string, mixed>
		 */
		public static function detect_smtp( $recent_failures = array() ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			if ( is_string( $recent_failures ) ) {
				$recent_failures = array(
					'count'       => '' !== $recent_failures ? 1 : 0,
					'last_error'  => $recent_failures,
					'provider'    => '',
					'recorded_at' => 0,
				);
			}

			$can_install  = current_user_can( 'install_plugins' );
			$can_activate = current_user_can( 'activate_plugins' );
			$setup_url    = admin_url( 'admin.php?page=smart-smtp&urm_setup=google_workspace&is_active=true&source=urm#/primary-connection' );
			$logs_url     = admin_url( 'admin.php?page=smart-smtp#/mail-logs' );
			$plugins_url  = admin_url( 'plugins.php' );
			$active_known = self::get_active_known_smtp_plugins();

			if ( is_plugin_active( 'smart-smtp/smart-smtp.php' ) ) {
				$report                        = self::inspect_smart_smtp( $recent_failures, $setup_url, $logs_url, $can_install, $can_activate );
				$report['conflicting_plugins'] = array_values( array_unique( wp_list_pluck( $active_known, 'name' ) ) );
				$report['plugins_url']         = $plugins_url;
				return $report;
			}

			if ( ! empty( $active_known ) ) {
				$primary   = self::select_primary_known_smtp( $active_known );
				$conflicts = array();
				foreach ( $active_known as $item ) {
					if ( $item['file'] !== $primary['file'] && $item['name'] !== $primary['name'] ) {
						$conflicts[] = $item['name'];
					}
				}

				return array(
					'status'                  => $primary['configured'] ? 'other-smtp' : 'other-smtp-unconfigured',
					'provider_type'           => '',
					'provider_label'          => $primary['name'],
					'other_smtp_name'         => $primary['name'],
					'other_smtp_settings_url' => $primary['settings_url'],
					'conflicting_plugins'     => array_values( array_unique( $conflicts ) ),
					'plugins_url'             => $plugins_url,
					'force_from'              => $primary['configured'] && $primary['force_from'],
					'account_email'           => '',
					'probe_error'             => '',
					'probe_error_owner'       => '',
					'setup_url'               => $setup_url,
					'logs_url'                => $logs_url,
					'smart_smtp_installed'    => file_exists( WP_PLUGIN_DIR . '/smart-smtp/smart-smtp.php' ),
					'can_install_plugins'     => $can_install,
					'can_activate_plugins'    => $can_activate,
				);
			}

			$probe = self::probe_third_party_transport();
			if ( '' !== $probe['owner'] ) {
				return array(
					'status'                  => 'other-smtp',
					'provider_type'           => '',
					'provider_label'          => $probe['owner'],
					'other_smtp_name'         => $probe['owner'],
					'other_smtp_settings_url' => '',
					'conflicting_plugins'     => array(),
					'plugins_url'             => $plugins_url,
					'force_from'              => $probe['force_from'],
					'account_email'           => '',
					'probe_error'             => $probe['probe_error'],
					'probe_error_owner'       => $probe['probe_error_owner'],
					'setup_url'               => $setup_url,
					'logs_url'                => $logs_url,
					'smart_smtp_installed'    => file_exists( WP_PLUGIN_DIR . '/smart-smtp/smart-smtp.php' ),
					'can_install_plugins'     => $can_install,
					'can_activate_plugins'    => $can_activate,
				);
			}

			return array(
				'status'                  => 'none',
				'provider_type'           => '',
				'provider_label'          => '',
				'other_smtp_name'         => '',
				'other_smtp_settings_url' => '',
				'conflicting_plugins'     => array(),
				'plugins_url'             => $plugins_url,
				'force_from'              => false,
				'account_email'           => '',
				'probe_error'             => $probe['probe_error'],
				'probe_error_owner'       => $probe['probe_error_owner'],
				'setup_url'               => $setup_url,
				'logs_url'                => $logs_url,
				'smart_smtp_installed'    => file_exists( WP_PLUGIN_DIR . '/smart-smtp/smart-smtp.php' ),
				'can_install_plugins'     => $can_install,
				'can_activate_plugins'    => $can_activate,
			);
		}

		/**
		 * Inspect SmartSMTP public completeness and stored options.
		 *
		 * @param array  $recent_failures Recent failure telemetry array.
		 * @param string $setup_url       Deep link to SmartSMTP primary connection setup.
		 * @param string $logs_url        Deep link to SmartSMTP mail logs.
		 * @param bool   $can_install     Whether current user can install plugins.
		 * @param bool   $can_activate    Whether current user can activate plugins.
		 * @return array<string, mixed>
		 */
		private static function inspect_smart_smtp( $recent_failures, $setup_url, $logs_url, $can_install, $can_activate ) {
			$provider_type = (string) get_option( 'smart_smtp_provider_type', 'default' );
			if ( '' === $provider_type ) {
				$provider_type = 'default';
			}

			$config = array();
			if ( 'default' !== $provider_type ) {
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

			// SmartSMTP's default mailer delegates to PHP mail(), so only non-default providers count as configured SMTP.
			if ( 'default' !== $provider_type ) {
				$is_complete = false;
				if ( class_exists( '\SmartSMTP\Controller\ProviderController' ) ) {
					try {
						$controller  = new \SmartSMTP\Controller\ProviderController();
						$is_complete = (bool) $controller->is_mailer_complete( 'primary', $provider_type );
					} catch ( Throwable $e ) {
						$is_complete = self::fallback_smart_smtp_complete( $provider_type, $config );
					}
				} else {
					$is_complete = self::fallback_smart_smtp_complete( $provider_type, $config );
				}

				if ( $is_complete ) {
					if ( 'gmail' === $provider_type ) {
						$status = self::is_gmail_token_expired( $config, $recent_failures ) ? 'token-expired' : 'connected';
					} else {
						$status = 'connected';
					}
				}
			}

			$account_email = '';
			if ( ! empty( $config['account_email'] ) ) {
				$account_email = sanitize_email( $config['account_email'] );
			} elseif ( ! empty( $config['smtp_from_email_address'] ) ) {
				$account_email = sanitize_email( $config['smtp_from_email_address'] );
			}

			$force_from = false;
			if ( 'connected' === $status && ! empty( $config['smtp_force_from_email'] ) ) {
				$force_from = ! empty( $config['one_click_setup'] ) || ! empty( $config['smtp_from_email_address'] );
			}

			return array(
				'status'                  => $status,
				'provider_type'           => $provider_type,
				'provider_label'          => $provider_label,
				'other_smtp_name'         => '',
				'other_smtp_settings_url' => '',
				'conflicting_plugins'     => array(),
				'plugins_url'             => admin_url( 'plugins.php' ),
				'force_from'              => $force_from,
				'account_email'           => $account_email,
				'probe_error'             => '',
				'probe_error_owner'       => '',
				'setup_url'               => $setup_url,
				'logs_url'                => $logs_url,
				'smart_smtp_installed'    => true,
				'can_install_plugins'     => $can_install,
				'can_activate_plugins'    => $can_activate,
			);
		}

		/**
		 * Fallback completeness check when SmartSMTP's controller class is unavailable.
		 *
		 * @param string $provider_type SmartSMTP provider slug.
		 * @param array  $config        Stored SmartSMTP provider configuration.
		 * @return bool
		 */
		private static function fallback_smart_smtp_complete( $provider_type, $config ) {
			if ( 'gmail' === $provider_type ) {
				return ! empty( $config['access_token'] );
			}

			if ( 'brevo' === $provider_type ) {
				if ( empty( $config['smtp_api_key'] ) ) {
					return false;
				}
				$validation = get_option( 'smart_smtp_brevo_mailer_validation', array() );
				return is_array( $validation ) && isset( $validation['code'] ) && 200 === (int) $validation['code'];
			}

			if ( 'other' === $provider_type ) {
				return ! empty( $config['smtp_host'] ) && ! empty( $config['smtp_port'] );
			}

			return ! empty( $config );
		}

		/**
		 * Determine whether a SmartSMTP Gmail token is expired and cannot auto-refresh.
		 *
		 * @param array $config          SmartSMTP Gmail configuration array.
		 * @param array $recent_failures Recent failure telemetry array.
		 * @return bool
		 */
		private static function is_gmail_token_expired( $config, $recent_failures ) {
			if ( get_option( 'smart_smtp_gmail_reconnect_required_primary', false ) ) {
				return true;
			}

			$raw_token     = isset( $config['access_token'] ) ? $config['access_token'] : '';
			$refresh_token = isset( $config['refresh_token'] ) ? (string) $config['refresh_token'] : '';
			$token_data    = is_array( $raw_token ) ? $raw_token : json_decode( (string) $raw_token, true );
			$token_created = 0;

			if ( is_array( $token_data ) ) {
				if ( isset( $token_data['created'] ) ) {
					$token_created = (int) $token_data['created'];
				}
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

			$recent_error    = isset( $recent_failures['last_error'] ) ? (string) $recent_failures['last_error'] : '';
			$recent_provider = isset( $recent_failures['provider'] ) ? (string) $recent_failures['provider'] : '';
			$recorded_at     = isset( $recent_failures['recorded_at'] ) ? (int) $recent_failures['recorded_at'] : 0;

			if ( '' !== $recent_error && 'smart-smtp:gmail' === $recent_provider && ( 0 === $token_created || 0 === $recorded_at || $recorded_at >= $token_created ) ) {
				$lower = strtolower( $recent_error );
				if ( false !== strpos( $lower, 'invalid_grant' ) || false !== strpos( $lower, 'gmail authentication failed' ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Collect all active known third-party SMTP plugins with their configuration state.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private static function get_active_known_smtp_plugins() {
			$active = array();

			foreach ( self::$known_smtp_plugins as $plugin_file => $plugin_name ) {
				if ( is_plugin_active( $plugin_file ) ) {
					$info     = self::inspect_known_smtp_plugin( $plugin_file );
					$slug     = isset( self::$known_smtp_settings_pages[ $plugin_file ] ) ? self::$known_smtp_settings_pages[ $plugin_file ] : '';
					$active[] = array(
						'file'         => $plugin_file,
						'name'         => $plugin_name,
						'configured'   => $info['configured'],
						'force_from'   => $info['force_from'],
						'settings_url' => '' !== $slug ? admin_url( $slug ) : '',
					);
				}
			}

			return $active;
		}

		/**
		 * Pick the first configured known SMTP plugin, or the first active one if none are configured.
		 *
		 * @param array<int, array<string, mixed>> $active_known Active known SMTP plugin entries.
		 * @return array<string, mixed>
		 */
		private static function select_primary_known_smtp( $active_known ) {
			foreach ( $active_known as $item ) {
				if ( ! empty( $item['configured'] ) ) {
					return $item;
				}
			}

			return $active_known[0];
		}

		/**
		 * Inspect a known third-party SMTP plugin to distinguish configured mailers from unconfigured installs.
		 *
		 * @param string $plugin_file Plugin basename.
		 * @return array<string, bool>
		 */
		private static function inspect_known_smtp_plugin( $plugin_file ) {
			switch ( $plugin_file ) {
				case 'wp-mail-smtp/wp_mail_smtp.php':
				case 'wp-mail-smtp-pro/wp_mail_smtp.php':
					return self::inspect_wp_mail_smtp_family( 'wp_mail_smtp', '\WPMailSMTP\Options', 'wp_mail_smtp' );

				case 'easy-wp-smtp/easy-wp-smtp.php':
					$v2 = self::inspect_wp_mail_smtp_family( 'easy_wp_smtp', '\EasyWPSMTP\Options', 'easy_wp_smtp' );
					if ( $v2['configured'] ) {
						return $v2;
					}
					$legacy = get_option( 'swpsmtp_options', array() );
					if ( is_array( $legacy ) && ! empty( $legacy['smtp_settings']['host'] ) ) {
						return array(
							'configured' => true,
							'force_from' => ! empty( $legacy['force_from_name_replace'] ) || ! empty( $legacy['from_email_field'] ),
						);
					}
					return $v2;

				case 'fluent-smtp/fluent-smtp.php':
					return self::inspect_fluent_smtp();

				case 'post-smtp/postman-smtp.php':
					return self::inspect_post_smtp();

				case 'smtp-mailer/main.php':
					$opts = get_option( 'smtp_mailer_options', array() );
					$ok   = is_array( $opts ) && ! empty( $opts['smtp_host'] );
					return array(
						'configured' => $ok,
						'force_from' => $ok && ! empty( $opts['from_email'] ),
					);

				case 'wp-smtp/wp-smtp.php':
					$opts = get_option( 'wp_smtp_options', array() );
					$ok   = is_array( $opts ) && ! empty( $opts['host'] );
					return array(
						'configured' => $ok,
						'force_from' => $ok && ! empty( $opts['from'] ),
					);

				case 'mailgun/mailgun.php':
					$opts = get_option( 'mailgun', array() );
					$ok   = is_array( $opts ) && ( ! empty( $opts['apiKey'] ) || ! empty( $opts['password'] ) );
					return array(
						'configured' => $ok,
						'force_from' => $ok && ! empty( $opts['override-from'] ),
					);

				case 'sendgrid-email-delivery-simplified/wpsendgrid.php':
					$ok = '' !== (string) get_option( 'sendgrid_api_key', '' ) || '' !== (string) get_option( 'sendgrid_user', '' );
					return array(
						'configured' => $ok,
						'force_from' => $ok && '' !== (string) get_option( 'sendgrid_from_email', '' ),
					);

				case 'wp-ses/wp-ses.php':
					$opts = get_option( 'wposes_settings', array() );
					$ok   = is_array( $opts ) && ! empty( $opts['send-via-ses'] );
					return array(
						'configured' => $ok,
						'force_from' => false,
					);

				case 'gravitysmtp/gravitysmtp.php':
					return self::inspect_gravity_smtp();

				case 'suremails/suremails.php':
					$opts = get_option( 'suremails_connections', array() );
					$ok   = is_array( $opts ) && ! empty( $opts['connections'] ) && is_array( $opts['connections'] );
					return array(
						'configured' => $ok,
						'force_from' => false,
					);

				case 'yay-smtp/yay-smtp.php':
					$opts   = get_option( 'yaysmtp_settings', array() );
					$mailer = is_array( $opts ) && isset( $opts['currentMailer'] ) ? (string) $opts['currentMailer'] : '';
					$ok     = '' !== $mailer && 'mail' !== $mailer;
					return array(
						'configured' => $ok,
						'force_from' => $ok && ! empty( $opts['fromEmailForce'] ),
					);

				case 'brevo-wp/brevo-wp.php':
				case 'mailin/sendinblue.php':
					$opts = get_option( 'sib_main_option', array() );
					$ok   = is_array( $opts ) && ! empty( $opts['api_key'] );
					return array(
						'configured' => $ok,
						'force_from' => false,
					);
			}

			return array(
				'configured' => false,
				'force_from' => false,
			);
		}

		/**
		 * Inspect WP Mail SMTP or Easy WP SMTP v2+ configuration.
		 *
		 * @param string $boot_fn      Bootstrap function name.
		 * @param string $options_cls  Options class name.
		 * @param string $option_key   Fallback WordPress option key.
		 * @return array<string, bool>
		 */
		private static function inspect_wp_mail_smtp_family( $boot_fn, $options_cls, $option_key ) {
			if ( function_exists( $boot_fn ) && class_exists( $options_cls ) ) {
				try {
					$options = call_user_func( array( $options_cls, 'init' ) );
					$mailer  = (string) $options->get( 'mail', 'mailer' );
					if ( '' === $mailer || 'mail' === $mailer ) {
						return array(
							'configured' => false,
							'force_from' => false,
						);
					}

					$plugin     = call_user_func( $boot_fn );
					$mailer_obj = $plugin->get_providers()->get_mailer(
						$mailer,
						$plugin->get_processor()->get_phpmailer()
					);
					$configured = ! empty( $mailer_obj ) && method_exists( $mailer_obj, 'is_mailer_complete' ) && (bool) $mailer_obj->is_mailer_complete();
					$force_from = $configured && (bool) $options->get( 'mail', 'from_email_force' );

					return array(
						'configured' => $configured,
						'force_from' => $force_from,
					);
				} catch ( Throwable $e ) {
					// Fall through to option inspection if plugin runtime API is only partially booted.
					unset( $e );
				}
			}

			$opts   = get_option( $option_key, array() );
			$mailer = is_array( $opts ) && isset( $opts['mail']['mailer'] ) ? (string) $opts['mail']['mailer'] : '';
			if ( '' === $mailer || 'mail' === $mailer ) {
				return array(
					'configured' => false,
					'force_from' => false,
				);
			}

			$configured = false;
			if ( 'smtp' === $mailer ) {
				$configured = ! empty( $opts['smtp']['host'] );
			} elseif ( isset( $opts[ $mailer ] ) && is_array( $opts[ $mailer ] ) ) {
				$configured = ! empty( array_filter( $opts[ $mailer ] ) );
			} else {
				$configured = true;
			}

			return array(
				'configured' => $configured,
				'force_from' => $configured && ! empty( $opts['mail']['from_email_force'] ),
			);
		}

		/**
		 * Inspect FluentSMTP connections and sender forcing rules.
		 *
		 * @return array<string, bool>
		 */
		private static function inspect_fluent_smtp() {
			$settings = function_exists( 'fluentMailGetSettings' ) ? fluentMailGetSettings( array(), false ) : get_option( 'fluentmail-settings', array() );
			if ( ! is_array( $settings ) || empty( $settings['connections'] ) || ! is_array( $settings['connections'] ) ) {
				return array(
					'configured' => false,
					'force_from' => false,
				);
			}

			$default_key = isset( $settings['misc']['default_connection'] ) ? (string) $settings['misc']['default_connection'] : '';
			$primary_ps  = null;

			if ( '' !== $default_key && isset( $settings['connections'][ $default_key ]['provider_settings'] ) ) {
				$candidate = $settings['connections'][ $default_key ]['provider_settings'];
				if ( is_array( $candidate ) && ! empty( $candidate['provider'] ) && 'default' !== $candidate['provider'] ) {
					$primary_ps = $candidate;
				}
			}

			if ( null === $primary_ps ) {
				foreach ( $settings['connections'] as $connection ) {
					if ( ! empty( $connection['provider_settings'] ) && is_array( $connection['provider_settings'] ) ) {
						$candidate = $connection['provider_settings'];
						if ( ! empty( $candidate['provider'] ) && 'default' !== $candidate['provider'] ) {
							$primary_ps = $candidate;
							break;
						}
					}
				}
			}

			if ( null === $primary_ps ) {
				return array(
					'configured' => false,
					'force_from' => false,
				);
			}

			// FluentSMTP forces sender_email unless force_from_email is explicitly set to 'no'.
			$force_from = ! empty( $primary_ps['sender_email'] ) && ( ! isset( $primary_ps['force_from_email'] ) || 'no' !== $primary_ps['force_from_email'] );

			return array(
				'configured' => true,
				'force_from' => $force_from,
			);
		}

		/**
		 * Inspect Post SMTP transport readiness and sender override setting.
		 *
		 * @return array<string, bool>
		 */
		private static function inspect_post_smtp() {
			if ( class_exists( 'PostmanOptions' ) && class_exists( 'PostmanTransportRegistry' ) ) {
				try {
					$opts           = PostmanOptions::getInstance();
					$transport_type = method_exists( $opts, 'getTransportType' ) ? (string) $opts->getTransportType() : '';
					$transport      = PostmanTransportRegistry::getInstance()->getActiveTransport();
					$configured     = '' !== $transport_type
						&& 'default' !== $transport_type
						&& $transport
						&& method_exists( $transport, 'isConfiguredAndReady' )
						&& (bool) $transport->isConfiguredAndReady();
					$force_from     = $configured
						&& method_exists( $opts, 'isSenderEmailOverridePrevented' )
						&& (bool) $opts->isSenderEmailOverridePrevented();

					return array(
						'configured' => $configured,
						'force_from' => $force_from,
					);
				} catch ( Throwable $e ) {
					// Fall back to saved options if Postman runtime classes fail to initialize.
					unset( $e );
				}
			}

			$opts           = get_option( 'postman_options', array() );
			$transport_type = is_array( $opts ) && isset( $opts['transport_type'] ) ? (string) $opts['transport_type'] : '';
			$configured     = '' !== $transport_type && 'default' !== $transport_type;

			return array(
				'configured' => $configured,
				'force_from' => $configured && ! empty( $opts['prevent_sender_email_override'] ),
			);
		}

		/**
		 * Inspect Gravity SMTP enabled connectors.
		 *
		 * @return array<string, bool>
		 */
		private static function inspect_gravity_smtp() {
			$enabled = get_option( 'gravitysmtp_enabled_connectors', array() );
			if ( is_array( $enabled ) && ! empty( $enabled ) ) {
				return array(
					'configured' => true,
					'force_from' => false,
				);
			}

			$connectors = get_option( 'gravitysmtp_connectors', array() );
			if ( is_array( $connectors ) && ! empty( $connectors ) ) {
				foreach ( $connectors as $connector ) {
					if ( is_array( $connector ) && ! empty( $connector['enabled'] ) ) {
						return array(
							'configured' => true,
							'force_from' => false,
						);
					}
				}
			}

			return array(
				'configured' => false,
				'force_from' => false,
			);
		}

		/**
		 * Probe pluggable wp_mail(), pre_wp_mail, and phpmailer_init callbacks once per request.
		 *
		 * @return array<string, mixed>
		 */
		private static function probe_third_party_transport() {
			if ( null !== self::$probe_cache ) {
				return self::$probe_cache;
			}

			$result = array(
				'owner'             => '',
				'force_from'        => false,
				'probe_error'       => '',
				'probe_error_owner' => '',
			);

			$replacement = self::wp_mail_replacement_owner();
			if ( '' !== $replacement ) {
				$result['owner']      = $replacement;
				$result['force_from'] = self::has_external_wp_mail_from_filter();
				self::$probe_cache    = $result;
				return $result;
			}

			$diverter = self::first_hook_owner( 'pre_wp_mail' );
			if ( '' !== $diverter ) {
				$result['owner']      = $diverter;
				$result['force_from'] = self::has_external_wp_mail_from_filter();
				self::$probe_cache    = $result;
				return $result;
			}

			global $wp_filter;
			if ( empty( $wp_filter['phpmailer_init'] ) || ! isset( $wp_filter['phpmailer_init']->callbacks ) ) {
				self::$probe_cache = $result;
				return $result;
			}

			if ( ! class_exists( 'PHPMailer\PHPMailer\PHPMailer' ) ) {
				require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
				require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
				require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			}

			$sentinel = 'ur-probe@example.invalid';
			foreach ( $wp_filter['phpmailer_init']->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( ! isset( $callback['function'] ) || ! is_callable( $callback['function'] ) ) {
						continue;
					}
					if ( self::is_own_callback( $callback['function'] ) ) {
						continue;
					}

					$cb_owner = self::describe_callback_owner( $callback['function'] );
					try {
						$candidate = new PHPMailer\PHPMailer\PHPMailer( true );
						$candidate->setFrom( $sentinel, 'UR Probe', false );
						call_user_func_array( $callback['function'], array( &$candidate ) );

						if ( isset( $candidate->Mailer ) && 'mail' !== $candidate->Mailer ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							$result['owner'] = '' !== $cb_owner ? $cb_owner : __( 'Custom SMTP', 'user-registration' );
							if ( isset( $candidate->From ) && '' !== $candidate->From && $sentinel !== $candidate->From ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
								$result['force_from'] = true;
							} elseif ( self::has_external_wp_mail_from_filter() ) {
								$result['force_from'] = true;
							}
							self::$probe_cache = $result;
							return $result;
						}
					} catch ( Throwable $e ) {
						if ( '' === $result['probe_error'] ) {
							$result['probe_error']       = trim( wp_strip_all_tags( (string) $e->getMessage() ) );
							$result['probe_error_owner'] = '' !== $cb_owner ? $cb_owner : __( 'Custom mail handler', 'user-registration' );
						}
					}
				}
			}

			self::$probe_cache = $result;
			return $result;
		}

		/**
		 * Check whether wp_mail_from filter overrides a sentinel sender address.
		 *
		 * @return bool
		 */
		private static function has_external_wp_mail_from_filter() {
			$sentinel = 'ur-probe@example.invalid';
			$filtered = (string) apply_filters( 'wp_mail_from', $sentinel );
			return $sentinel !== $filtered && '' !== $filtered;
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
					'post_type'              => 'user_registration',
					'post_status'            => 'publish',
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => true,
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
			if ( self::is_local_host( $site_domain ) ) {
				$matches = '' !== $first && (bool) is_email( $first );
			} elseif ( '' !== $from_domain && is_email( $first ) && self::is_valid_alignable_domain( $from_domain ) ) {
				$matches = ( $from_domain === $site_domain )
					|| ( self::is_valid_alignable_domain( $site_domain ) && self::ends_with( $from_domain, '.' . $site_domain ) )
					|| self::ends_with( $site_domain, '.' . $from_domain );
			}

			return array(
				'raw'            => $raw,
				'first'          => $first,
				'matches_domain' => $matches,
				'suggested'      => '' !== $site_domain ? 'noreply@' . $site_domain : $admin_email,
			);
		}

		/**
		 * Guard against matching bare TLDs or public suffixes (e.g. "com", "co.uk") as a parent domain.
		 *
		 * @param string $domain Normalized domain name.
		 * @return bool
		 */
		private static function is_valid_alignable_domain( $domain ) {
			if ( '' === $domain || '.' === $domain[0] || '.' === substr( $domain, -1 ) || false === strpos( $domain, '.' ) ) {
				return false;
			}

			if ( in_array( $domain, self::$multi_label_public_suffixes, true ) ) {
				return false;
			}

			if ( 1 === preg_match( '/^(co|com|org|net|edu|gov|ac|mil|ne|or|go|gr|lg)\.[a-z]{2}$/', $domain ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Read recent mail delivery failures recorded for Email Health.
		 *
		 * @return array<string, mixed>
		 */
		private static function get_recent_failures() {
			$failed_data = get_transient( self::FAILURE_TRANSIENT );
			if ( ! is_array( $failed_data ) ) {
				$failed_data = get_transient( self::LEGACY_FAILURE_TRANSIENT );
			}

			$empty = array(
				'count'       => 0,
				'last_error'  => '',
				'source'      => '',
				'provider'    => '',
				'recorded_at' => 0,
			);

			if ( ! is_array( $failed_data ) ) {
				return $empty;
			}

			$provider        = isset( $failed_data['provider'] ) ? (string) $failed_data['provider'] : '';
			$active_provider = self::get_active_provider_slug();
			if ( '' !== $provider && $provider !== $active_provider ) {
				// Stale errors captured under a prior mail transport must not taint a newly configured provider.
				return $empty;
			}

			$count       = isset( $failed_data['failed_count'] ) ? (int) $failed_data['failed_count'] : 0;
			$raw_error   = isset( $failed_data['error_message'] ) ? (string) $failed_data['error_message'] : '';
			$clean_error = self::clean_failure_message( $raw_error );

			return array(
				'count'       => $count,
				'last_error'  => $clean_error,
				'source'      => isset( $failed_data['source'] ) ? (string) $failed_data['source'] : '',
				'provider'    => $provider,
				'recorded_at' => isset( $failed_data['recorded_at'] ) ? (int) $failed_data['recorded_at'] : 0,
			);
		}

		/**
		 * Strip HTML and suppress legacy Status Log boilerplate from failure messages.
		 *
		 * @param string $raw_error Raw failure message.
		 * @return string
		 */
		private static function clean_failure_message( $raw_error ) {
			$clean_error = trim( wp_strip_all_tags( (string) $raw_error ) );

			if ( false !== strpos( $clean_error, 'ur_mail_logs' ) && false !== strpos( $clean_error, 'Status Log' ) ) {
				return '';
			}

			return $clean_error;
		}

		/**
		 * Return a lightweight identifier for the currently active mail transport.
		 *
		 * @return string
		 */
		private static function get_active_provider_slug() {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			if ( is_plugin_active( 'smart-smtp/smart-smtp.php' ) ) {
				$provider_type = (string) get_option( 'smart_smtp_provider_type', 'default' );
				return 'smart-smtp:' . ( '' !== $provider_type ? $provider_type : 'default' );
			}

			foreach ( self::$known_smtp_plugins as $plugin_file => $plugin_name ) {
				if ( is_plugin_active( $plugin_file ) ) {
					return 'other-smtp:' . $plugin_file;
				}
			}

			return 'none';
		}

		/**
		 * Determine the Email Health sidebar dot state matching the diagnostic card severity.
		 *
		 * @param array $report Diagnostic report array.
		 * @return string 'critical' | 'warning' | ''
		 */
		private static function compute_sidebar_dot( $report ) {
			$smtp_status = isset( $report['smtp']['status'] ) ? $report['smtp']['status'] : 'none';
			$incoming    = isset( $report['incoming_issue'] ) ? $report['incoming_issue'] : '';

			$has_critical = ! empty( $report['emails_disabled'] )
				|| ( ! empty( $report['email_confirmation']['disabled'] ) && ! empty( $report['email_confirmation']['form_requires_confirmation'] ) )
				|| 'token-expired' === $smtp_status
				|| ! empty( $report['smtp']['probe_error'] )
				|| ( isset( $report['recent_failures']['count'] ) && (int) $report['recent_failures']['count'] > 0 )
				|| ! empty( $report['recent_failures']['last_error'] )
				|| 'failed' === $incoming;

			if ( $has_critical ) {
				return 'critical';
			}

			$has_warning = ! empty( $report['email_confirmation']['disabled'] )
				|| 'unconfigured' === $smtp_status
				|| 'other-smtp-unconfigured' === $smtp_status
				|| ! empty( $report['smtp']['conflicting_plugins'] )
				|| '' !== $incoming;

			if ( $has_warning ) {
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
		 * Check whether a host is a local development hostname rather than a public domain.
		 *
		 * @param string $host Normalized hostname.
		 * @return bool
		 */
		private static function is_local_host( $host ) {
			if ( '' === $host || in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
				return true;
			}

			foreach ( array( '.local', '.test', '.localhost', '.invalid' ) as $suffix ) {
				if ( self::ends_with( $host, $suffix ) ) {
					return true;
				}
			}

			return false;
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
