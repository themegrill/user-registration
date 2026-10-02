<?php
/**
 * UR_Stats Class for tracking non-sensitive information about the plugin and add-on usage.
 *
 * Explore more what information is shared https://docs.wpuserregistration.com/global-settings/advanced-settings/#allow-usage-tracking
 *
 * @package User_Registration_Pro
 * @since  1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/class-ur-stats-helpers.php';

if ( ! class_exists( 'UR_Stats' ) ) {

	/**
	 * UR_Stats class.
	 */
	class UR_Stats {


		/**
		 * Remote URl Constant.
		 */
		const REMOTE_URL = 'https://api.themegrill.com/';

		const LAST_RUN_STAMP = 'user_registration_send_usage_last_run';

		const OPTION_ONBOARDING_SNAPSHOT = 'urm_onboarding_snapshot';

		/**
		 * Constructor of the class.
		 */
		public function __construct() {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			add_action( 'init', array( $this, 'init_usage' ), 4 );
			add_action( 'update_option_user_registration_allow_usage_tracking', array( $this, 'run_on_save' ), 10, 3 );

			/**
			 * Enable module tracking.
			 *
			 * @since 4.0
			 */
			add_action(
				'user_registration_feature_track_data_for_tg_user_tracking',
				array(
					$this,
					'on_module_activate',
				)
			); // Hook on module activation ( Our UR module activation ).
		}

		/**
		 * Get product license key.
		 */
		public function get_base_product_license() {
			return get_option( 'user-registration_license_key' );
		}

		/**
		 * Get Pro addon file.
		 */
		public function get_base_product() {
			if ( $this->is_premium() ) {
				return 'user-registration-pro';
			} else {
				return 'user-registration';
			}
		}

		/**
		 * Check if user is using premium version.
		 *
		 * @return boolean
		 */
		public function is_premium() {
			if ( is_plugin_active( 'user-registration-pro/user-registration.php' ) ) {
				return true;
			} else {
				return false;
			}
		}

		public function get_form_wise_user() {
			return array(
				'membership_form_users' => $this->get_form_users_count( true ),
				'normal_form_users'     => $this->get_form_users_count(),
			);
		}

		/**
		 * Get membership payment gateway usage statistics.
		 *
		 * Retrieves the total number of orders grouped by payment method
		 * from the membership orders table.
		 *
		 * @since 1.0.0
		 * 
		 * @return array An array of payment methods and their corresponding order counts.
		 */
		public function get_membership_gateway_usage() {
			global $wpdb;

			$table = $wpdb->prefix . 'ur_membership_orders';

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return array();
			}

			return $wpdb->get_results(
				"SELECT payment_method, COUNT(*) AS total FROM {$table} GROUP BY payment_method ORDER BY total DESC",
				ARRAY_A
			);
		}

		/**
		 * @param $type
		 *
		 * @return string|null
		 */
		public function get_form_users_count( $for_membership = false ) {
			global $wpdb;
			if ( $for_membership ) {
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT wum.meta_value AS ur_form_id,
			                COUNT(DISTINCT wu.ID) AS total
							FROM {$wpdb->users} wu
							         JOIN {$wpdb->usermeta} wum
							              ON wum.user_id = wu.ID
							                  AND wum.meta_key = %s
							         JOIN {$wpdb->usermeta} wpum
							              ON wpum.user_id = wu.ID
							                  AND wpum.meta_key = %s
							                  AND wpum.meta_value = %s
							GROUP BY wum.meta_value
							ORDER BY total DESC;",
						'ur_form_id',
						'ur_registration_source',
						'membership'
					),
					ARRAY_A
				);
			}

			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT wum.meta_value AS ur_form_id,
				       COUNT(DISTINCT wu.ID) AS total
						FROM {$wpdb->users} wu
						         JOIN {$wpdb->usermeta} wum
						              ON wum.user_id = wu.ID
						                  AND wum.meta_key = %s
						WHERE NOT EXISTS (SELECT 1
						                  FROM {$wpdb->usermeta} wpum
						                  WHERE wpum.user_id = wu.ID
						                    AND wpum.meta_key = %s
						                    AND wpum.meta_value = %s)
						GROUP BY wum.meta_value
						ORDER BY total DESC;",
					'ur_form_id',
					'ur_registration_source',
					'membership'
				),
				ARRAY_A
			);
		}

		/**
		 * Returns total users registered from User Registration forms.
		 *
		 * @return int
		 */
		public function get_user_count() {
			global $wpdb;

			return $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $wpdb->users
		LEFT JOIN $wpdb->usermeta ON $wpdb->users.ID = $wpdb->usermeta.user_id
		WHERE meta_key = %s
		AND meta_value != ''",
					'ur_form_id'
				)
			);
		}

		/**
		 * Returns total number of  registration forms created using this plugin.
		 *
		 * @return int
		 */
		public function get_form_count() {
			global $wpdb;

			return $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $wpdb->posts WHERE post_type=%s AND post_status=%s",
					'user_registration',
					'publish'
				)
			);
		}

		/**
		 * Returns list of all active plugins.
		 *
		 * @return array
		 */
		public function get_plugin_lists() {
			$is_premium        = $this->is_premium();
			$base_product      = $this->get_base_product();
			$base_product_name = $is_premium ? 'User Registration & Membership Pro' : 'User Registration & Membership';
			$license_key       = $this->get_base_product_license();
			$form_wise_users   = $this->get_form_wise_user();
			$active_plugins    = get_option( 'active_plugins', array() );

			$addons_data = array(
				array(
					'product_name'          => $base_product_name,
					'product_version'       => UR()->version,
					'product_type'          => 'plugin',
					'product_slug'          => $base_product,
					'is_premium'            => $is_premium,
					'license_key'           => $is_premium ? $license_key : '',
					'total_form_count'      => $this->get_form_count(),
					'total_user_count'      => $this->get_user_count(),
					'membership_form_users'   => $form_wise_users['membership_form_users'],
					'normal_form_users'       => $form_wise_users['normal_form_users'],
					'membership_gateway_usage' => $this->get_membership_gateway_usage(),
				),
			);

			foreach ( $active_plugins as $plugin ) {

				$addon_file      = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $plugin;
				$addon_file_data = get_plugin_data( $addon_file );
				$plugin_slug     = class_exists( 'UR_Stats_Helpers' ) ? UR_Stats_Helpers::extract_plugin_slug( $plugin ) : ( false !== strpos( $plugin, '/' ) ? explode( '/', $plugin )[0] : $plugin );

				if ( $base_product !== $plugin && strpos( $plugin_slug, 'user-registration-' ) === 0 ) {
					$addon_info = array(
						'product_name'    => isset( $addon_file_data['Name'] ) ? trim( $addon_file_data['Name'] ) : '',
						'product_version' => isset( $addon_file_data['Version'] ) ? trim( $addon_file_data['Version'] ) : '',
						'product_type'    => 'plugin',
						'product_slug'    => $plugin,
					);

					if ( class_exists( 'UR_Stats_Helpers' ) ) {
						$addon_info = UR_Stats_Helpers::maybe_add_email_template_stats( $addon_info, $plugin );
					}

					$addons_data[] = $addon_info;
				}
			}

			/**
			 * Format module data to track in TG Tracking.
			 *
			 * @since 4.0
			 */
			$enabled_features = array_unique( get_option( 'user_registration_enabled_features', array() ) );

			$addons_list_moved_into_module = array(
				'user-registration-payments',
				'user-registration-content-restriction',
				'user-registration-frontend-listing',
				'user-registration-membership',
			);

			if ( ! empty( $enabled_features ) ) {
				$our_modules     = $this->get_modules();
				$modules_by_slug = array_column( $our_modules, null, 'slug' );

				foreach ( $enabled_features as $slug ) {
					if ( isset( $modules_by_slug[ $slug ] ) ) {
						$module               = $modules_by_slug[ $slug ];
						$is_moved_addon       = in_array( $slug, $addons_list_moved_into_module, true );
						$is_standalone_active = $is_moved_addon && in_array( $slug . '/' . $slug . '.php', $active_plugins, true );
						$product_slug         = $is_standalone_active ? $slug . '/' . $slug . '.php' : $slug;
						$addon_info           = array(
							'product_name'    => $module['name'],
							'product_version' => UR()->version,
							'product_type'    => $is_standalone_active ? 'plugin' : 'module',
							'product_slug'    => $product_slug,
							'is_premium'      => $is_premium,
						);

						// Add content restriction stats if it's the content-restriction module
						if ( class_exists( 'UR_Stats_Helpers' ) && $is_premium ) {
							$addon_info = UR_Stats_Helpers::maybe_add_content_restriction_stats( $addon_info, $slug );
							$addon_info = UR_Stats_Helpers::maybe_add_email_template_stats( $addon_info, $slug );
						}

						$addons_data[] = $addon_info;
					}
				}
			}

			return $addons_data;
		}


		/**
		 * Get non-sensitive settings.
		 *
		 * @return array
		 */
		public function get_global_settings() {
			$global_settings = array();
			$settings        = $this->setting_keys();
			$send_all        = false;
			$send_default    = false;

			foreach ( $settings as $product => $product_settings ) {
				foreach ( $product_settings as $setting_array ) {
					$setting_key     = $setting_array[0];
					$setting_default = $setting_array[1];
					$value           = get_option( $setting_key, 'NOT_SET' );

					// Set boolean values for certain settings.
					if ( isset( $setting_array[2] ) && 'NOT_SET' !== $value && $setting_default !== $value ) {
						$value = 1;
					}

					if ( 'NOT_SET' !== $value || $send_all ) {
						$global_settings[] = array(
							'type'          => 'global',
							'setting_key'   => $setting_key,
							'setting_value' => is_array($value) ? json_encode($value) : $value, //phpcs:ignore
							'default_value' => $setting_default,
						);
					}
				}
			}

			return $global_settings;
		}

		/**
		 * Checks if usage is allowed.
		 *
		 * @return boolean
		 */
		public function is_usage_allowed() {
			return ur_option_checked( 'user_registration_allow_usage_tracking', false );
		}

		/**
		 * Start process.
		 *
		 * @return void
		 */
		public function init_usage() {
			if ( wp_doing_cron() ) {
				add_action( 'user_registration_usage_stats_scheduled_events', array( $this, 'process' ) );
			}
		}

		/**
		 * Run the process once when user gives consent.
		 *
		 * @param int   $old_value Old Value.
		 * @param int   $value Value.
		 * @param mixed $option Options.
		 *
		 * @return mixed
		 */
		public function run_on_save( $old_value, $value, $option ) {
			if ( $value !== $old_value && $value && ( false === get_option( self::LAST_RUN_STAMP ) ) ) {
				$this->process();
			}

			return $value;
		}

		/**
		 * Start process.
		 *
		 * @return void
		 */
		public function process() {

			if ( ! $this->is_usage_allowed() ) {
				return;
			}

			$last_send = get_option( self::LAST_RUN_STAMP );

			// Make sure we do not run it more than once on each 15 days.
			if (
				false !== $last_send &&
				( time() - $last_send ) < ( DAY_IN_SECONDS * 14 )
			) {
				return;
			}

			$this->call_api();
			// Update the last run option to the current timestamp.
			update_option( self::LAST_RUN_STAMP, time() );
		}

		/**
		 * Get api stats url.
		 *
		 * @return string
		 * @since 4.3.0
		 */
		private function get_stats_api_url() {
			return self::REMOTE_URL . ( ( defined( 'UR_DEV' ) && UR_DEV ) ? 'dev/log' : 'tracking/log' );
		}

		/**
		 * Get Form Settings.
		 *
		 * @return array
		 */
		public function get_form_settings() {
			$form_settings = array();
			$forms         = ur_get_all_user_registration_form();

			if ( ! empty( $forms ) ) {
				foreach ( $forms as $form_id => $form ) {
					$form_specific_settings = $this->get_form_specific_settings( $form_id );
					$form_settings          = array_merge( $form_settings, $form_specific_settings );
				}
			}

			return $form_settings;
		}

		/**
		 * Get Form Specific Settings.
		 * 'form_settings' =>
		 * );
		 *
		 * @param int $form_id Form ID.
		 *
		 * @return array
		 */
		private function get_form_specific_settings( $form_id ) {

			$form_settings = ur_admin_form_settings_fields( $form_id );
			$settings      = array();

			if ( ! empty( $form_settings ) ) {
				foreach ( $form_settings as $setting ) {

					$setting_id = $setting['id'];

					$product = ! empty( $setting['product'] ) ? explode( '/', $setting['product'] )[0] : '';

					$value                  = get_post_meta( $form_id, $setting_id, true );
					$settings_value         = empty( $value ) ? 'NOT_SET' : get_post_meta( $form_id, $setting_id, true );
					$default_value          = ! empty( $setting['default_value'] ) ? $setting['default_value'] : '';
					$settings_default_value = is_bool( $default_value ) ? ur_bool_to_string( $default_value ) : $default_value;

					// Convert arrays and other non-scalar values to JSON strings to avoid array to string conversion warnings
					$settings_value_str         = is_scalar( $settings_value ) ? (string) $settings_value : wp_json_encode( $settings_value );
					$settings_default_value_str = is_scalar( $settings_default_value ) ? (string) $settings_default_value : wp_json_encode( $settings_default_value );

					$settings[] = array(
						'type'          => 'form',
						'setting_key'   => $setting_id,
						'setting_value' => strpos( $settings_value_str, '<br>' ) !== false ? preg_replace( '#<\s*br\s*/?\s*>#i', ' ', $settings_value_str ) : $settings_value_str,
						'default_value' => strpos( $settings_default_value_str, '<br>' ) !== false ? preg_replace( '#<\s*br\s*/?\s*>#i', ' ', $settings_default_value_str ) : $settings_default_value_str,
						'form_id'       => $form_id,
					);
				}
			}

			return $settings;
		}

		/**
		 * Get onboarding snapshot data.
		 *
		 * @since x.x.x
		 *
		 * @return array
		 */
		public function get_onboarding_data() {
			$onboarding = get_option( self::OPTION_ONBOARDING_SNAPSHOT, array() );

			if ( ! is_array( $onboarding ) ) {
				return array();
			}

			return $onboarding;
		}


		/**
		 * Call API.
		 *
		 * @return void
		 */
		public function call_api() {
			global $wpdb;
			ur_get_logger()->debug( '------------- TG SDK API log tracking initiated -------------', array( 'source' => 'urm-tg-sdk-logs' ) );

			$stats_api_url = $this->get_stats_api_url();
			if ( '' === $stats_api_url ) {
				return;
			}
			$data        = $this->get_base_info();
			$popup_count = 0;
			if ( class_exists( 'UR_Stats_Helpers' ) ) {
				$popup_count = UR_Stats_Helpers::get_popup_stats();
			}

			$data['data'] = array(
				'registration_type' => get_option( 'urm_initial_registration_type', '' ),
				'admin_email'       => get_bloginfo( 'admin_email' ),
				'website_url'       => get_bloginfo( 'url' ),
				'php_version'       => phpversion(),
				'mysql_version'     => $wpdb->db_version(),
				'server_software'   => ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '' ),
				'is_ssl'            => is_ssl(),
				'is_multisite'      => is_multisite(),
				'is_wp_com'         => defined( 'IS_WPCOM' ) && IS_WPCOM,
				'is_wp_com_vip'     => ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV ) || ( function_exists( 'wpcom_is_vip' ) && wpcom_is_vip() ),
				'is_wp_cache'       => defined( 'WP_CACHE' ) && WP_CACHE,
				'multi_site_count'  => $this->get_sites_total(),
				'timezone'          => $this->get_timezone_offset(),
				'total_popup_count' => $popup_count,
				'base_product'      => $this->get_base_product(),
				'product_info'      => $this->get_plugin_lists(),
				'settings'          => array_merge( $this->get_global_settings(), $this->get_form_settings() ),
				'onboarding'        => $this->get_onboarding_data(),
			);

			$this->send_request( apply_filters( 'user_registration_tg_tracking_remote_url', $stats_api_url ), $data );
		}

		/**
		 * Returns total sites.
		 *
		 * @return int
		 */
		private function get_sites_total() {

			return function_exists( 'get_blog_count' ) ? (int) get_blog_count() : 1;
		}

		/**
		 * Get Timezone Offset.
		 */
		private function get_timezone_offset() {

			// It was added in WordPress 5.3.
			if ( function_exists( 'wp_timezone_string' ) ) {
				return wp_timezone_string();
			}

			/*
			 * The code below is basically a copy-paste from that function.
			 */

			$timezone_string = get_option( 'timezone_string' );

			if ( $timezone_string ) {
				return $timezone_string;
			}

			$offset  = (float) get_option( 'gmt_offset' );
			$hours   = (int) $offset;
			$minutes = ( $offset - $hours );

			$sign      = ( $offset < 0 ) ? '-' : '+';
			$abs_hour  = abs( $hours );
			$abs_mins  = abs( $minutes * 60 );
			$tz_offset = sprintf( '%s%02d:%02d', $sign, $abs_hour, $abs_mins );

			return $tz_offset;
		}

		/**
		 * Send Request to API.
		 *
		 * @param string $url URL.
		 * @param array  $data Data.
		 */
		public function send_request( $url, $data ) {
			$headers  = array(
				'Content-Type' => 'application/json',
				'User-Agent'   => 'ThemeGrillSDK',
			);
			$response = wp_remote_post(
				$url,
				array(
					'method'      => 'POST',
					'timeout'     => 45,
					'redirection' => 5,
					'httpversion' => '1.0',
					'blocking'    => true,
					'headers'     => $headers,
					'body'        => wp_json_encode( $data ),
				)
			);
			ur_get_logger()->notice( print_r( json_decode( wp_remote_retrieve_body( $response ), true ), true ), array( 'source' => 'urm-tg-sdk-logs' ) );
			ur_get_logger()->debug( '------------- TG SDK API log tracking response received -------------', array( 'source' => 'urm-tg-sdk-logs' ) );

			return json_decode( wp_remote_retrieve_body( $response ), true );
		}

		/**
		 * Returns non-sensitive setting keys.
		 *
		 * @return array
		 */
		private function setting_keys() {
			return array(
				'user-registration'                => array(
					// General Settings
					array( 'user_registration_general_setting_disabled_user_roles', '["subscriber"]' ),
					array( 'user_registration_myaccount_page_id', '', true ),
					array( 'user_registration_my_account_layout', 'vertical' ),
					array( 'user_registration_general_setting_registration_url_options', '', true ),
					array(
						'user_registration_general_setting_registration_label',
						__( 'Not a member yet? Register now.', 'user-registration' ),
					),
					array( 'user_registration_general_setting_uninstall_option', false ),
					array( 'user_registration_allow_usage_tracking', false ),

					// Login Settings
					array( 'user_registration_login_option_hide_show_password', false ),
					array( 'user_registration_ajax_form_submission_on_edit_profile', false ),
					array( 'user_registration_disable_profile_picture', false ),
					array(
						'user_registration_disable_logout_confirmation',
						apply_filters( 'user_registration_disable_logout_confirmation_status', true ),
					),
					array( 'user_registration_login_options_form_template', 'default' ),
					array( 'user_registration_general_setting_login_options_with', 'default' ),
					array( 'user_registration_login_title', false ),
					array( 'user_registration_general_setting_login_form_title', __( 'Welcome', 'user-registration' ) ),
					array( 'user_registration_general_setting_login_form_desc', '' ),
					array( 'ur_login_ajax_submission', false ),
					array( 'user_registration_login_options_remember_me', true ),
					array( 'user_registration_login_options_lost_password', true ),
					array( 'user_registration_login_options_hide_labels', false ),
					array( 'user_registration_login_options_enable_recaptcha', false ),
					array( 'user_registration_login_options_prevent_core_login', false ),
					array( 'user_registration_login_options_login_redirect_url', '', true ),
					array( 'user_registration_login_options_configured_captcha_type', 'v2' ),

					// Captcha Settings
					array( 'user_registration_captcha_setting_recaptcha_version', 'v2' ),
					array( 'user_registration_captcha_setting_recaptcha_site_key', '' ),
					array( 'user_registration_captcha_setting_recaptcha_site_secret', '' ),
					array( 'user_registration_captcha_setting_recaptcha_site_key_v3', '' ),
					array( 'user_registration_captcha_setting_recaptcha_site_secret_v3', '' ),
					array( 'user_registration_captcha_setting_recaptcha_site_key_hcaptcha', '' ),
					array( 'user_registration_captcha_setting_recaptcha_site_secret_hcaptcha', '' ),
					array( 'user_registration_captcha_setting_recaptcha_site_key_cloudflare', '' ),
					array( 'user_registration_captcha_setting_recaptcha_site_secret_cloudflare', '' ),
					array( 'user_registration_captcha_setting_invisible_recaptcha_v2', false ),
					array( 'user_registration_captcha_setting_recaptcha_cloudflare_theme', 'light' ),

					// Email Settings
					array( 'user_registration_email_setting_disable_email', false ),
				),
				'user-registration-pro'            => array(
					array( 'user_registration_pro_general_setting_delete_account', 'disable' ),
					array( 'user_registration_pro_general_setting_login_form', false ),
					array( 'user_registration_pro_general_setting_prevent_active_login', false ),
					array( 'user_registration_pro_general_setting_limited_login', '5' ),
					array( 'user_registration_pro_general_setting_redirect_back_to_previous_page', false ),
					array( 'user_registration_pro_general_post_submission_settings', '' ),
					array( 'user_registration_pro_general_setting_post_submission', 'disable' ),
					array('user_registration_pro_role_based_redirection', false),//phpcs:ignore
					array( 'user_registration_payment_currency', 'USD' ),
					array( 'user_registration_paypal_enabled', false ),
					array( 'user_registration_stripe_enabled', false ),
					array( 'user_registration_authorize-net_enabled', false ),
					array( 'user_registration_mollie_enabled', false ),
					array( 'user_registration_bank_enabled', false ),
					array( 'user_registration_content_restriction_enable', true ),
					array('user_registration_content_restriction_allow_to_roles', '["administrator"]') //phpcs:ignore
				),
				'user-registration-file-upload'    => array(
					array( 'user_registration_file_upload_setting_valid_file_type', '["pdf"]' ),
					array('user_registration_file_upload_setting_max_file_size', '1024') //phpcs:ignore
				),
				'user-registration-pdf-submission' => array(
					array( 'user_registration_pdf_template', 'default' ),
					array( 'user_registration_pdf_logo_image', '', true ),
					array( 'user_registration_pdf_setting_header', '' ),
					array( 'user_registration_pdf_custom_header_text', '', true ),
					array( 'user_registration_pdf_paper_size', '' ),
					array( 'user_registration_pdf_orientation', 'portrait' ),
					array( 'user_registration_pdf_font', '' ),
					array( 'user_registration_pdf_font_size', '12' ),
					array( 'user_registration_pdf_font_color', '#000000' ),
					array( 'user_registration_pdf_background_color', '#ffffff' ),
					array( 'user_registration_pdf_header_font_color', '#000000' ),
					array( 'user_registration_pdf_header_background_color', '#ffffff' ),
					array( 'user_registration_pdf_multiple_column', false ),
					array( 'user_registration_pdf_rtl', false ),
					array( 'user_registration_pdf_print_user_default_fields', false ),
					array('user_registration_pdf_hide_empty_fields', false) //phpcs:ignore
				),
				'user-registration-social-connect' => array(
					array( 'user_registration_social_setting_enable_facebook_connect', '' ),
					array( 'user_registration_social_setting_enable_twitter_connect', '' ),
					array( 'user_registration_social_setting_enable_google_connect', '' ),
					array( 'user_registration_social_setting_enable_linkedin_connect', '' ),
					array( 'user_registration_social_setting_enable_social_registration', false ),
					array( 'user_registration_social_setting_display_social_buttons_in_registration', false ),
					array( 'user_registration_social_setting_default_user_role', 'subscriber' ),
					array( 'user_registration_social_login_position', 'bottom' ),
					array('user_registration_social_login_template', 'ursc_theme_4') //phpcs:ignore
				),
				'user-registration-two-factor-authentication' => array(
					array( 'user_registration_tfa_enable_disable', false ),
					array( 'user_registration_tfa_roles', '["subscriber"]' ),
					array( 'user_registration_tfa_otp_length', '6' ),
					array( 'user_registration_tfa_otp_expiry_time', '10' ),
					array( 'user_registration_tfa_otp_resend_limit', '3' ),
					array( 'user_registration_tfa_incorrect_otp_limit', '5' ),
					array('user_registration_tfa_login_hold_period', '60') //phpcs:ignore
				),
			);
		}

		/**
		 * Track module installation data.
		 *
		 * @param string $slug Slug.
		 *
		 * @since 4.0
		 */
		public function on_module_activate( $slug ) {
			$our_modules  = $this->get_modules();
			$module_lists = wp_list_pluck( $our_modules, 'slug' );

			if ( ! in_array( $slug, $module_lists, true ) ) {
				return;
			}

			$this->call_api();
		}

		/**
		 * Get all modules.
		 *
		 * @since 4.0
		 */
		public function get_modules() {
			$all_modules = file_get_contents( ur()->plugin_path() . '/assets/extensions-json/all-features.json' );

			if ( ur_is_json( $all_modules ) ) {
				$all_modules = json_decode( $all_modules, true );
			}

			return isset( $all_modules['features'] ) ? $all_modules['features'] : array();
		}

		/**
		 * @return array
		 */
		public function get_base_info() {
			$data                = array();
			$theme               = wp_get_theme();
			$data['site']        = get_bloginfo( 'url' );
			$data['slug']        = 'user-registration';
			$data['version']     = UR()->version;
			$data['wp_version']  = get_bloginfo( 'version' );
			$data['locale']      = get_locale();
			$data['license']     = $this->get_base_product_license();
			$data['environment'] = array(
				'plugins' => array_values( get_option( 'active_plugins' ) ),
				'theme'   => array(
					'name'   => $theme->name,
					'author' => $theme->author,
					'parent' => $theme->parent() !== false ? $theme->parent()->get( 'Name' ) : $theme->get( 'Name' ),
				),
			);

			return $data;
		}
	}
}

new UR_Stats();
