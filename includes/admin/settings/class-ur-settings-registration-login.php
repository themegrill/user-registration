<?php
/**
 * Class UR_Settings_Registration_Login
 *
 * Handles the registration_login related settings for the User Registration & Membership plugin.
 *
 * This class is responsible for:
 *
 * @package   UserRegistration\Admin
 * @version   5.0.0
 * @since     5.0.0
 */
if ( ! class_exists( 'UR_Settings_Registration_Login' ) ) {
	/**
	 * UR_Settings_Registration_Login Class
	 */
	class UR_Settings_Registration_Login extends UR_Settings_Page {
        private static $_instance = null;
		/**
		 * Constructor.
		 */
		private function __construct() {
			$this->id    = 'registration_login';
			$this->label = __( 'Registration & Login', 'user-registration' );
            parent::__construct();
            $this->handle_hooks();
		}
        public static function get_instance() {
            if ( null === self::$_instance ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }
        /**
         * Register hooks for submenus and section UI.
         * @return void
         */
        public function handle_hooks() {
            add_filter( "user_registration_get_sections_{$this->id}",  array( $this, 'get_sections_callback' ), 1, 1 );
            add_filter( "user_registration_get_settings_{$this->id}", array( $this, 'get_settings_callback' ), 1, 1 );
        }
        /**
         * Filter to provide sections submenu for registration_login settings.
         */
        public function get_sections_callback( $sections ) {
            $sections[ 'messages' ] =  __( 'Messages', 'user-registration' );
            $sections[ 'captcha' ] =  __( 'Captcha', 'user-registration' );
            $sections[ 'social-connect' ] =  __( 'Social Connect', 'user-registration' );
            $sections[ 'profile-connect' ] =  __( 'Profile Connect', 'user-registration' );
            $sections[ 'popup' ] =  __( 'Popups', 'user-registration' );
            $sections[ 'invite-code' ] =  __( 'Invite Codes', 'user-registration' );
            $sections[ 'file-upload' ] =  __( 'File Upload', 'user-registration' );

            return $sections;
        }
        /**
         * Filter to provide sections UI for registration_login settings.
         */
        public function get_settings_callback( $settings ) {
            global $current_section;
			if ( 'messages' === $current_section ) {
				return $this->get_messages_settings();
			}
			if ( 'captcha' === $current_section ) {
				add_filter( 'user_registration_settings_hide_save_button', '__return_true' );
				return $this->get_captcha_settings();
			}
			if ( in_array( $current_section, [ 'social-connect', 'profile-connect', 'popup', 'invite-code', 'file-upload', 'role-based-redirection' ] ) ) {
				return $this->upgrade_to_pro_setting();
			}
            return $settings;
        }
		/**
		 * Settings for frontend messages customization.
		 *
		 * @return array
		 */
		public function get_messages_settings() {
			/**
			 * Filter to add the frontend messages options settings.
			 *
			 * @param array Options to be enlisted.
			 */
			$settings = apply_filters(
				'user_registration_frontend_messages_settings',
				array(
					'title'    => '',
					'sections' => array(
						'frontend_success_messages_settings' => array(
							'title'    => __( 'Success Messages', 'user-registration' ),
							'type'     => 'card',
							'desc'     => '',
							'settings' => array(
								array(
									'title'    => __( 'Auto Approval And Manual Login ', 'user-registration' ),
									'desc'     => __( 'Enter the text message after successful form submission when auto approval and manual login is selected.', 'user-registration' ),
									'id'       => 'user_registration_successful_form_submission_message_manual_registation',
									'type'     => 'textarea',
									'desc_tip' => true,
									'css'      => 'min-width: 350px; min-height: 100px;',
									'default'  => __( 'User successfully registered.', 'user-registration' ),
								),

								array(
									'title'    => __( 'Auto Approval After Email Confirmation', 'user-registration' ),
									'desc'     => __( 'Enter the text message after successful form submission when auto approval and email confirmation is selected.', 'user-registration' ),
									'id'       => 'user_registration_successful_form_submission_message_email_confirmation',
									'type'     => 'textarea',
									'desc_tip' => true,
									'css'      => 'min-width: 350px; min-height: 100px;',
									'default'  => __( 'User registered. Verify your email by clicking on the link sent to your email.', 'user-registration' ),
								),

								array(
									'title'    => __( 'Email Verification Completed', 'user-registration' ),
									'desc'     => __( 'Enter the text message that appears after the email is successfully verified and have access login access.', 'user-registration' ),
									'id'       => 'user_registration_successful_email_verified_message',
									'type'     => 'textarea',
									'desc_tip' => true,
									'css'      => 'min-width: 350px; min-height: 100px;',
									'default'  => __( 'User successfully registered. Login to continue.', 'user-registration' ),
								),

								array(
									'title'    => __( 'Admin Approval', 'user-registration' ),
									'desc'     => __( 'Enter the text message that appears after successful form submission when admin approval is selected.', 'user-registration' ),
									'id'       => 'user_registration_successful_form_submission_message_admin_approval',
									'type'     => 'textarea',
									'desc_tip' => true,
									'css'      => 'min-width: 350px; min-height: 100px;',
									'default'  => __( 'User registered. Wait until admin approves your registration.', 'user-registration' ),
								),

								array(
									'title'    => __( 'Membership Registration', 'user-registration' ),
									'desc'     => __( 'Enter the text message that appears after successful membership registration.', 'user-registration' ),
									'id'       => 'user_registration_successful_membership_creation_message',
									'type'     => 'textarea',
									'desc_tip' => true,
									'css'      => 'min-width: 350px; min-height: 100px;',
									'default'  => __( 'New member has been successfully created.', 'user-registration' ),
								),
							),
						),
						'frontend_error_message_messages_settings' => array(
							'title'    => __( 'Error Messages', 'user-registration' ),
							'type'     => 'card',
							'desc'     => '',
							'settings' => array(
								array(
									'title'    => __( 'Required', 'user-registration' ),
									'desc'     => __( 'Enter the error message in form submission on required fields.', 'user-registration' ),
									'id'       => 'user_registration_form_submission_error_message_required_fields',
									'type'     => 'text',
									'desc_tip' => true,
									'css'      => '',
									'default'  => __( 'This field is required.', 'user-registration' ),
								),
								array(
									'title'    => __( 'Special Character Validation in Username', 'user-registration' ),
									'desc'     => __( 'Enter the error message in form submission on username', 'user-registration' ),
									'id'       => 'user_registration_form_submission_error_message_disallow_username_character',
									'type'     => 'text',
									'desc_tip' => true,
									'css'      => '',
									'default'  => __( 'Please enter the valid username', 'user-registration' ),
								),
								array(
									'title'    => __( 'Email', 'user-registration' ),
									'desc'     => __( 'Enter the error message in form submission on Email.', 'user-registration' ),
									'id'       => 'user_registration_form_submission_error_message_email',
									'type'     => 'text',
									'desc_tip' => true,
									'css'      => '',
									'default'  => __( 'Please enter a valid email address.', 'user-registration' ),
								),

								array(
									'title'    => __( 'Website URL', 'user-registration' ),
									'desc'     => __( 'Enter the error message in form submission on website/URL.', 'user-registration' ),
									'id'       => 'user_registration_form_submission_error_message_website_URL',
									'type'     => 'text',
									'desc_tip' => true,
									'css'      => '',
									'default'  => __( 'Please enter a valid URL.', 'user-registration' ),
								),

								array(
									'title'    => __( 'Number', 'user-registration' ),
									'desc'     => __( 'Enter the error message in form submission on Number.', 'user-registration' ),
									'id'       => 'user_registration_form_submission_error_message_number',
									'type'     => 'text',
									'desc_tip' => true,
									'css'      => '',
									'default'  => __( 'Please enter a valid number.', 'user-registration' ),
								),

								array(
									'title'    => __( 'Confirm Email', 'user-registration' ),
									'desc'     => __( 'Enter the error message in form submission on Confim Email.', 'user-registration' ),
									'id'       => 'user_registration_form_submission_error_message_confirm_email',
									'type'     => 'text',
									'desc_tip' => true,
									'css'      => '',
									'default'  => __( 'Email and confirm email not matched.', 'user-registration' ),
								),

								array(
									'title'    => __( 'Confirm Password', 'user-registration' ),
									'desc'     => __( 'Enter the error message in form submission on Confim Password.', 'user-registration' ),
									'id'       => 'user_registration_form_submission_error_message_confirm_password',
									'type'     => 'text',
									'desc_tip' => true,
									'css'      => '',
									'default'  => __( 'Password and confirm password not matched.', 'user-registration' ),
								),

								array(
									'title'    => __( 'reCAPTCHA', 'user-registration' ),
									'desc'     => __( 'Enter the error message in form submission on recaptcha.', 'user-registration' ),
									'id'       => 'user_registration_form_submission_error_message_recaptcha',
									'type'     => 'text',
									'desc_tip' => true,
									'css'      => '',
									'default'  => __( 'Captcha code error, please try again.', 'user-registration' ),
								),
							),
						),
					),
				)
			);

			/**
			 * Filter to get the settings.
			 *
			 * @param array $settings Frontend Message Setting options to be enlisted.
			 */
			return apply_filters( 'user_registration_get_frontend_messages_settings_' . $this->id, $settings );
		}
		public function get_captcha_settings() {
			$recaptcha_type = get_option( 'user_registration_captcha_setting_recaptcha_version', 'v2' );
			$invisible      = get_option( 'user_registration_captcha_setting_invisible_recaptcha_v2', 'no' );

			/**
			 * Filter to add the options on settings.
			 *
			 * @param array Options to be enlisted.
			 */
			$settings = apply_filters(
				'user_registration_captcha_settings',
				array(
					'title'    => '',
					'sections' => $this->get_captcha_global_settings(),
				)
			);

			/**
			 * Filter to get the settings.
			 *
			 * @param array $settings Captcha Setting options to be enlisted.
			 */
			return apply_filters( 'user_registration_get_captcha_settings_' . $this->id, $settings );
		}
		public function get_captcha_global_settings() {
			$captcha_global_settings = array();

			$captcha_global_settings = array(
				'payment_settings' => array(
					'id'          => 'captcha-settings',
					'title'       => esc_html__( 'Captcha Settings', 'user-registration' ),
					'type'        => 'card',
					'desc'        => '',
					'show_status' => false,
					'show_logo'   => false,
					'settings'    => array(
						array(
							'title'    => __( 'Prevent Captcha Conflicts', 'user-registration' ),
							'desc'     => __( "When enabled, removes other plugins' captcha scripts (e.g. WPForms, Contact Form 7) site-wide to prevent duplicate or broken captcha widgets. It does not add or enable a captcha on your own forms - set that up separately for each form.", 'user-registration' ),
							'id'       => 'urm_enable_no_conflict',
							'default'  => false,
							'type'     => 'toggle',
							'css'      => 'min-width: 350px;',
							'desc_tip' => true
						),
						array(
							'title' => __( 'Save', 'user-registration' ),
							'id'    => 'user_registration_captcha_save_settings',
							'type'  => 'button',
							'class' => 'captcha-save-btn'
						),
					),
				),
				'v2'               => array(
					'title'         => 'reCAPTCHA v2',
					'type'          => 'accordian',
					'id'            => 'v2',
					'is_connected'  => get_option( 'user_registration_captcha_setting_recaptcha_enable_v2', false ),
					'settings'      => array(
						array(
							'title'    => __( 'Site Key (reCAPTCHA v2)', 'user-registration' ),
							/* translators: %1$s - Google reCAPTCHA docs url */
							'desc'     => sprintf( __( 'Get site key from google %1$s reCAPTCHA %2$s.', 'user-registration' ), '<a href="https://www.google.com/recaptcha" rel="noreferrer noopener" target="_blank">', '</a>' ),
							'id'       => 'user_registration_captcha_setting_recaptcha_site_key',
							'default'  => '',
							'type'     => 'text',
							'class'    => '',
							'css'      => 'min-width: 350px;',
							'desc_tip' => true,
						),
						array(
							'title'    => __( 'Secret Key (reCAPTCHA v2)', 'user-registration' ),
							/* translators: %1$s - Google reCAPTCHA docs url */
							'desc'     => sprintf( __( 'Get secret key from google %1$s reCAPTCHA %2$s.', 'user-registration' ), '<a href="https://www.google.com/recaptcha" rel="noreferrer noopener" target="_blank">', '</a>' ),
							'id'       => 'user_registration_captcha_setting_recaptcha_site_secret',
							'default'  => '',
							'type'     => 'text',
							'class'    => '',
							'css'      => 'min-width: 350px;',
							'desc_tip' => true,
						),
						array(
							'title'    => __( 'Site Key (Invisible reCAPTCHA v2)', 'user-registration' ),
							/* translators: %1$s - Google reCAPTCHA docs url */
							'desc'     => sprintf( __( 'Get site key from google %1$s reCAPTCHA %2$s.', 'user-registration' ), '<a href="https://www.google.com/recaptcha" rel="noreferrer noopener" target="_blank">', '</a>' ),
							'id'       => 'user_registration_captcha_setting_recaptcha_invisible_site_key',
							'default'  => '',
							'type'     => 'text',
							'class'    => '',
							'css'      => 'min-width: 350px;',
							'desc_tip' => true,

						),
						array(
							'title'    => __( 'Secret Key (Invisible reCAPTCHA v2)', 'user-registration' ),
							/* translators: %1$s - Google reCAPTCHA docs url */
							'desc'     => sprintf( __( 'Get secret key from google %1$s reCAPTCHA %2$s.', 'user-registration' ), '<a href="https://www.google.com/recaptcha" rel="noreferrer noopener" target="_blank">', '</a>' ),
							'id'       => 'user_registration_captcha_setting_recaptcha_invisible_site_secret',
							'default'  => '',
							'type'     => 'text',
							'class'    => '',
							'css'      => 'min-width: 350px;',
							'desc_tip' => true,
						),
						array(
							'title'    => __( 'Invisible reCAPTCHA', 'user-registration' ),
							/* translators: %1$s - Google reCAPTCHA docs url */
							'desc'     => sprintf( __( 'check this to enable invisible reCAPTCHA.', 'user-registration' ), '<a href="https://www.google.com/recaptcha" rel="noreferrer noopener" target="_blank">', '</a>' ),
							'id'       => 'user_registration_captcha_setting_invisible_recaptcha_v2',
							'default'  => 'no',
							'type'     => 'toggle',
							'css'      => 'min-width: 350px;',
							'desc_tip' => true,
						),
						array(
							'title' => __( 'Save', 'user-registration' ),
							'id'    => 'user_registration_captcha_save_settings',
							'type'  => 'button',
							'class' => 'captcha-save-btn',
						),
					),
					'settings_type' => 'captcha',
				),
				'v3'               => array(
					'title'         => 'reCAPTCHA v3',
					'type'          => 'accordian',
					'settings_type' => 'captcha',
					'id'            => 'v3',
					'is_connected'  => get_option( 'user_registration_captcha_setting_recaptcha_enable_v3', false ),
					'settings'      => array(
						array(
							'title'    => __( 'Site Key (reCAPTCHA v3)', 'user-registration' ),
							/* translators: %1$s - Google reCAPTCHA docs url */
							'desc'     => sprintf( __( 'Get site key from google %1$s reCAPTCHA %2$s.', 'user-registration' ), '<a href="https://www.google.com/recaptcha" rel="noreferrer noopener" target="_blank">', '</a>' ),
							'id'       => 'user_registration_captcha_setting_recaptcha_site_key_v3',
							'default'  => '',
							'type'     => 'text',
							'class'    => '',
							'css'      => 'min-width: 350px;',
							'desc_tip' => true,

						),
						array(
							'title'    => __( 'Secret Key (reCAPTCHA v3)', 'user-registration' ),
							/* translators: %1$s - Google reCAPTCHA docs url */
							'desc'     => sprintf( __( 'Get secret key from google %1$s reCAPTCHA %2$s.', 'user-registration' ), '<a href="https://www.google.com/recaptcha" rel="noreferrer noopener" target="_blank">', '</a>' ),
							'id'       => 'user_registration_captcha_setting_recaptcha_site_secret_v3',
							'default'  => '',
							'type'     => 'text',
							'class'    => '',
							'css'      => 'min-width: 350px;',
							'desc_tip' => true,
						),
						array(
							'title'             => __( 'Threshold score', 'user-registration' ),
							'desc'              => esc_html__( 'reCAPTCHA v3 returns a score (1.0 is very likely a good interaction, 0.0 is very likely a bot). If the score less than or equal to this threshold.', 'user-registration' ),
							'id'                => 'user_registration_captcha_setting_recaptcha_threshold_score_v3',
							'type'              => 'number',
							'custom_attributes' => array(
								'step' => '0.1',
								'min'  => '0.0',
								'max'  => '1.0',
							),
							'default'           => '0.4',
							'css'               => 'min-width: 350px;',
							'desc_tip'          => true,
						),
						array(
							'title' => __( 'Save', 'user-registration' ),
							'id'    => 'user_registration_captcha_save_settings',
							'type'  => 'button',
							'class' => 'captcha-save-btn',
						),
					),
				),
				'hCaptcha'         => array(
					'title'         => 'hCaptcha',
					'type'          => 'accordian',
					'settings_type' => 'captcha',
					'id'            => 'hCaptcha',
					'is_connected'  => get_option( 'user_registration_captcha_setting_recaptcha_enable_hCaptcha', false ),
					'settings'      => array(
						array(
							'title'    => __( 'Site Key (hCaptcha)', 'user-registration' ),
							/* translators: %1$s - hCaptcha docs url */
							'desc'     => sprintf( __( 'Get site key from %1$s hCaptcha %2$s.', 'user-registration' ), '<a href="https://www.hcaptcha.com/" rel="noreferrer noopener" target="_blank">', '</a>' ),
							//phpcs:ignore
							'id'       => 'user_registration_captcha_setting_recaptcha_site_key_hcaptcha',
							'default'  => '',
							'type'     => 'text',
							'class'    => '',
							'css'      => 'min-width: 350px;',
							'desc_tip' => true,
						),
						array(
							'title'    => __( 'Secret Key (hCaptcha)', 'user-registration' ),
							/* translators: %1$s - hCaptcha docs url */
							'desc'     => sprintf( __( 'Get secret key from %1$s hCaptcha %2$s.', 'user-registration' ), '<a href="https://www.hcaptcha.com/" rel="noreferrer noopener" target="_blank">', '</a>' ),
							//phpcs:ignore
							'id'       => 'user_registration_captcha_setting_recaptcha_site_secret_hcaptcha',
							'default'  => '',
							'type'     => 'text',
							'class'    => '',
							'css'      => 'min-width: 350px;',
							'desc_tip' => true,
						),
						array(
							'title' => __( 'Save', 'user-registration' ),
							'id'    => 'user_registration_captcha_save_settings',
							'type'  => 'button',
							'class' => 'captcha-save-btn',
						),
					),
				),
				'cloudflare'       => array(
					'title'         => 'Cloudflare Turnstile',
					'type'          => 'accordian',
					'settings_type' => 'captcha',
					'id'            => 'cloudflare',
					'is_connected'  => get_option( 'user_registration_captcha_setting_recaptcha_enable_cloudflare', false ),
					'settings'      => array(
						array(
							'title'    => __( 'Site Key (Cloudflare Turnstile)', 'user-registration' ),
							/* translators: %1$s - Cloudflare Turnstile docs url */
							'desc'     => sprintf( __( 'Get site key from %1$s Cloudflare Turnstile %2$s.', 'user-registration' ), '<a href="https://www.cloudflare.com/products/turnstile/" rel="noreferrer noopener" target="_blank">', '</a>' ),
							//phpcs:ignore
							'id'       => 'user_registration_captcha_setting_recaptcha_site_key_cloudflare',
							'default'  => '',
							'type'     => 'text',
							'class'    => '',
							'css'      => 'min-width: 350px;',
							'desc_tip' => true,

						),
						array(
							'title'    => __( 'Secret Key (Cloudflare Turnstile)', 'user-registration' ),
							/* translators: %1$s - Cloudflare Turnstile docs url */
							'desc'     => sprintf( __( 'Get secret key from %1$s Cloudflare Turnstile %2$s.', 'user-registration' ), '<a href="https://www.cloudflare.com/products/turnstile/" rel="noreferrer noopener" target="_blank">', '</a>' ),
							//phpcs:ignore
							'id'       => 'user_registration_captcha_setting_recaptcha_site_secret_cloudflare',
							'default'  => '',
							'type'     => 'text',
							'class'    => '',
							'css'      => 'min-width: 350px;',
							'desc_tip' => true,
						),
						array(
							'title'    => __( 'Theme', 'user-registration' ),
							/* translators: %1$s - Cloudflare Turnstile docs url */
							'desc'     => sprintf( esc_html__( 'Please select theme mode for your Cloudflare Turnstile. <a href="%1$s" rel="noreferrer noopener" target="_blank">Learn More</a>', 'user-registration' ), esc_url( 'https://www.cloudflare.com/products/turnstile/' ) ),
							'id'       => 'user_registration_captcha_setting_recaptcha_cloudflare_theme',
							'options'  => array(
								'auto'  => esc_html__( 'Auto', 'user-registration' ),
								'light' => esc_html__( 'Light', 'user-registration' ),
								'dark'  => esc_html__( 'Dark', 'user-registration' ),
							),
							'type'     => 'select',
							'class'    => '',
							'css'      => 'min-width: 350px;',
							'desc_tip' => true,
						),
						array(
							'title' => __( 'Save', 'user-registration' ),
							'id'    => 'user_registration_captcha_save_settings',
							'type'  => 'button',
							'class' => 'captcha-save-btn',
						),
					),
				)
			);

			return $captcha_global_settings;
		}
	}
}

//Backward Compatibility.
return method_exists( 'UR_Settings_Registration_Login', 'get_instance' ) ? UR_Settings_Registration_Login::get_instance() : new UR_Settings_Registration_Login();
