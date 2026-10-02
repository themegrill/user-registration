<?php
/**
 * UserRegistration General Settings
 *
 * @class    UR_Settings_Captcha
 * @version  1.0.0
 * @package  UserRegistration/Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'UR_Settings_Captcha' ) ) :

	/**
	 * UR_Settings_Captcha Class
	 */
	class UR_Settings_Captcha extends UR_Settings_Page {

		/**
		 * Setting Id.
		 *
		 * @var string
		 */
		public $id = 'captcha';

		/**
		 * Constructor.
		 */
		public function __construct() {

			$this->id    = 'captcha';
			$this->label = __( 'Captcha', 'user-registration' );

			add_filter( 'user_registration_settings_tabs_array', array( $this, 'add_settings_page' ), 20 );
			add_action( 'user_registration_settings_' . $this->id, array( $this, 'output' ) );
			add_action( 'user_registration_settings_save_' . $this->id, array( $this, 'save' ) );
			add_action( 'urm_save_captcha_settings', array( $this, 'save_captcha_settings' ), 10, 2 );
		}

		public function save_captcha_settings( $form_data, $setting_id ) {
			foreach ( $form_data as $key => $value ) {
				update_option( $key, sanitize_text_field( $value ) );
			}

			// Update the global captcha version to match the current setting
			if ( in_array( $setting_id, array( 'v2', 'v3', 'hCaptcha', 'cloudflare' ) ) ) {
				update_option( 'user_registration_captcha_setting_recaptcha_version', $setting_id );
			}

			// Mark captcha as enabled/connected after successful save
			update_option( 'user_registration_captcha_setting_recaptcha_enable_' . $setting_id, true );
		}

		/**
		 * Get settings
		 *
		 * @return array
		 */
		public function get_settings() {
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

		/**
		 * Save settings.
		 */
		public function save() {
			$settings = $this->get_settings();
			UR_Admin_Settings::save_fields( $settings );
		}

		/**
		 * Returns the active captcha settings.
		 * Returns false if captcha is not set or settings empty.
		 *
		 * @return array or boolean
		 */
		public static function get_active_captcha( $value ) {
			$captcha_type = $value['captcha_type'];

			switch ( $captcha_type ) {
				case 'v2':
					$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key' );
					$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret' );
					$invisible  = get_option( 'user_registration_captcha_setting_invisible_recaptcha_v2' );

					if ( ! empty( $site_key ) && ! empty( $secret_key ) ) {
						return array(
							'type'       => 'v2',
							'site_key'   => $site_key,
							'secret_key' => $secret_key,
							'invisible'  => $invisible,
						);
					}

					if ( $invisible ) {
						$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_invisible_site_key' );
						$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_invisible_site_secret' );

						if ( ! empty( $site_key ) && ! empty( $secret_key ) ) {
							return array(
								'type'       => 'invisible_v2',
								'site_key'   => $site_key,
								'secret_key' => $secret_key,
								'invisible'  => $invisible,
							);
						}
					}

					break;

				case 'v3':
					$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key_v3' );
					$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_v3' );
					$threshold  = get_option( 'user_registration_captcha_setting_recaptcha_threshold_score_v3', '0.4' );

					if ( ! empty( $site_key ) && ! empty( $secret_key ) ) {
						return array(
							'type'       => 'v3',
							'site_key'   => $site_key,
							'secret_key' => $secret_key,
							'threshold'  => $threshold,
						);
					}
					break;

				case 'hCaptcha':
					$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key_hcaptcha' );
					$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_hcaptcha' );

					if ( ! empty( $site_key ) && ! empty( $secret_key ) ) {
						return array(
							'type'       => 'hCaptcha',
							'site_key'   => $site_key,
							'secret_key' => $secret_key,
						);
					}
					break;

				case 'cloudflare':
					$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key_cloudflare' );
					$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_cloudflare' );

					if ( ! empty( $site_key ) && ! empty( $secret_key ) ) {
						return array(
							'type'       => 'cloudflare',
							'site_key'   => $site_key,
							'secret_key' => $secret_key,
						);
					}
					break;
			}

			/**
			 * Filter to change the status of recaptcha.
			 *
			 * @param bool false Status for the recaptcha.
			 */
			return apply_filters( 'user_registration_active_recaptcha', false );
		}

		/**
		 * Output the settings.
		 */
		public function output() {
			global $current_section, $hide_save_button;
			$settings = $this->get_settings();
			// $hide_save_button = true;

			UR_Admin_Settings::output_fields( $settings );
			$GLOBALS['hide_save_button'] = true;
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

		/**
		 * validate_captcha_settings
		 *
		 * @param $setting_id
		 * @param $form_data
		 *
		 * @return array
		 */
		public function validate_captcha_settings( $setting_id, $form_data ) {
			$validation = array( 'status' => true );

			$validation_config = array(
				'v2'         => array(
					'site_key_field'             => 'user_registration_captcha_setting_recaptcha_site_key',
					'secret_key_field'           => 'user_registration_captcha_setting_recaptcha_site_secret',
					'invisible_site_key_field'   => 'user_registration_captcha_setting_recaptcha_invisible_site_key',
					'invisible_secret_key_field' => 'user_registration_captcha_setting_recaptcha_invisible_site_secret',
					'invisible_toggle_field'     => 'user_registration_captcha_setting_invisible_recaptcha_v2'
				),
				'v3'         => array(
					'site_key_field'   => 'user_registration_captcha_setting_recaptcha_site_key_v3',
					'secret_key_field' => 'user_registration_captcha_setting_recaptcha_site_secret_v3'
				),
				'hCaptcha'   => array(
					'site_key_field'   => 'user_registration_captcha_setting_recaptcha_site_key_hcaptcha',
					'secret_key_field' => 'user_registration_captcha_setting_recaptcha_site_secret_hcaptcha'
				),
				'cloudflare' => array(
					'site_key_field'   => 'user_registration_captcha_setting_recaptcha_site_key_cloudflare',
					'secret_key_field' => 'user_registration_captcha_setting_recaptcha_site_secret_cloudflare',
					'theme_mode'       => 'user_registration_captcha_setting_recaptcha_cloudflare_theme'
				)
			);

			if ( isset( $validation_config[ $setting_id ] ) ) {
				$validation = self::validate_captcha_keys( $form_data, $validation_config[ $setting_id ] );
			}

			$response['status'] = $validation['status'];
			if ( ! $validation['status'] ) {
				$response['message'] = $validation['message'];
			}
			if ( ! empty( $validation['ur_recaptcha_code'] ) ) {
				$response['ur_recaptcha_code'] = $validation['ur_recaptcha_code'];
			}

			return $response;
		}

		/**
		 * Generic validation method for captcha keys
		 *
		 * @param array $form_data Form data
		 * @param array $config Validation configuration
		 *
		 * @return array Validation result
		 */
		private static function validate_captcha_keys( $form_data, $config ) {
			$is_invisible = false;
			$theme_mode   = '';
			// Handle v2 invisible recaptcha special case
			if ( isset( $config['invisible_toggle_field'] ) && isset( $form_data[ $config['invisible_toggle_field'] ] ) && $form_data[ $config['invisible_toggle_field'] ] ) {
				$is_invisible     = true;
				$site_key_field   = $config['invisible_site_key_field'];
				$secret_key_field = $config['invisible_secret_key_field'];
			} else {
				$site_key_field   = $config['site_key_field'];
				$secret_key_field = $config['secret_key_field'];
				$theme_mode       = isset( $config['theme_mode'] ) && isset( $form_data[ $config['theme_mode'] ] ) ? $form_data[ $config['theme_mode'] ] : $theme_mode;
			}

			if ( empty( $form_data[ $site_key_field ] ) ) {
				return array(
					'status'  => false,
					'message' => __( 'Site Key is required.', 'user-registration' )
				);
			}

			if ( empty( $form_data[ $secret_key_field ] ) ) {
				return array(
					'status'  => false,
					'message' => __( 'Secret Key is required.', 'user-registration' )
				);
			}

			return array(
				'status'            => true,
				'ur_recaptcha_code' => array(
					'site_key'     => $form_data[ $site_key_field ],
					'is_invisible' => $is_invisible,
					'theme_mode'   => $theme_mode
				)
			);
		}
	}

endif;

return new UR_Settings_Captcha();
