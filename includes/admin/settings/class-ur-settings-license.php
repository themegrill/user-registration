<?php
/**
 * Class UR_Settings_License
 *
 * Handles the scaffold related settings for the User Registration & Membership plugin.
 *
 * This class is responsible for:
 *
 * @package   UserRegistration\Admin
 * @version   5.0.0
 * @since     5.0.0
 */
if ( ! class_exists( 'UR_Settings_License' ) ) {
	/**
	 * UR_Settings_License Class
	 */
	class UR_Settings_License extends UR_Settings_Page {
		private static $_instance = null;
		/**
		 * Constructor.
		 */
		private function __construct() {
			$this->id    = 'license';
			$this->label = __( 'License', 'user-registration' );
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
		 *
		 * @return void
		 */
		public function handle_hooks() {
			add_filter( "user_registration_get_settings_{$this->id}", array( $this, 'get_settings_callback' ), 1, 1 );

			if ( isset( $_GET['tab'] ) && 'license' === $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				add_filter( 'user_registration_setting_save_label', array( $this, 'user_registration_license_setting_label' ) );
				add_filter( 'user_registration_admin_field_license_options', array( $this, 'license_options_settings' ), 10, 2 );
				add_filter( 'user_registration_setting_save_button_classes', array( $this, 'user_registration_license_setting_classes' ) );
			}
		}
		/**
		 * Filter to provide sections UI for scaffold settings.
		 *
		 * @param array $settings Settings.
		 * @return array
		 */
		public function get_settings_callback( $settings ) {
			return $this->get_license_settings();
		}

		/**
		 * Confirmation message for deactivate license links.
		 *
		 * @return string
		 */
		public static function get_deactivate_confirm_message() {
			return __( 'Deactivate this license? This site will stop receiving updates and support until a license is activated again.', 'user-registration' );
		}

		/**
		 * Build license settings UI.
		 *
		 * @return array
		 */
		public function get_license_settings() {
			$license_key = get_option( 'user-registration_license_key' );
			$settings    = array(
				'title'    => '',
				'sections' => array(
					'license_options_settings' => array(
						'title'    => __( 'License Activation', 'user-registration' ),
						'type'     => 'card',
						'settings' => array(
							array(
								'title'    => __( 'License Key', 'user-registration' ),
								'desc'     => __( 'Please enter the license key', 'user-registration' ),
								'id'       => 'user-registration_license_key',
								'default'  => '',
								'type'     => 'text',
								'css'      => '',
								'desc_tip' => true,
							),
							array(
								'id'     => 'ur_license_nonce',
								'action' => '_ur_license_nonce',
								'type'   => 'nonce',
							),
						),
					),
				),
			);
			// only show the content on free version.
			if ( is_plugin_active( 'user-registration/user-registration.php' ) ) {
				if ( $license_key ) {
					$settings['sections']['license_options_settings']['desc']        = '';
					$settings['sections']['license_options_settings']['before_desc'] = wp_kses_post( '<div class="urm_license_setting_notice urm_install_pro_notice"><h3><span class="dashicons dashicons-info-outline notice-icon"></span>' . __( 'Complete Your Pro Setup', 'user-registration' ) . '</h3><p>' . __( 'Your license is activated, but User Registration & Membership pro plugin needs to be installed to unlock all features. This is a one-time setup that takes less than a minute.', 'user-registration' ) . '</p><button class="button install_pro_version_button">' . __( 'Install Pro Version', 'user-registration' ) . '</button></div>' );
				} else {
					$settings['sections']['license_options_settings']['before_desc'] = sprintf( __( 'You\'re currently using the free version of User Registration & Membership.<br>You can continue using all free features without any limitations.<br><br>Want more? <a target="_blank" href="%s">Upgrade to Pro</a> to unlock advanced features and premium support.<br>Already purchased Pro? Enter your license key below and we\'ll automatically upgrade you to Pro.', 'user-registration' ), esc_url( ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => 'ur-license-setting', 'medium' => 'upgrade-link' ) ) ) );
				}
			} elseif ( $license_key ) {
					$settings['sections']['license_options_settings']['before_desc'] = __( 'Your Pro license is active! Enjoy all premium features and priority support.', 'user-registration' );
			} else {
				$settings['sections']['license_options_settings']['before_desc'] = __( 'You\'re using the Pro version, but your license needs to be activated.<br>Enter your license key below to unlock Pro features and receive updates.', 'user-registration' );
			}

			// Replace license input box and display deactivate license button when license is activated.
			if ( $license_key ) {
				$deactivate_url = wp_nonce_url(
					remove_query_arg(
						array( 'deactivated_license', 'activated_license' ),
						add_query_arg( 'user-registration_deactivate_license', 1 )
					),
					'_ur_license_nonce'
				);

				$settings['sections']['license_options_settings']['settings'] = array(
					array(
						'title'    => __( 'Deactivate License', 'user-registration' ),
						'desc'     => '',
						'desc_tip' => __( 'Deactivate the license of User Registration plugin', 'user-registration' ),
						'type'     => 'link',
						'id'       => 'user-registration_deactivate-license_key',
						'css'      => 'background:red; border:none; color:white;',
						'buttons'  => array(
							array(
								'title' => __( 'Deactivate License', 'user-registration' ),
								'href'  => $deactivate_url,
								'class' => 'ur-button user_registration-deactivate-license-key ur-deactivate-license-confirm',
							),
						),
					),
					array(
						'type' => 'license_options',
						'id'   => 'user_registration_license_section_settings',
					),
				);
				$GLOBALS['hide_save_button'] = true;
			}
			return $settings;
		}
		public function user_registration_license_setting_label() {
			return esc_html__( 'Activate License', 'user-registration' );
		}

		/**
		 * Render plan / expiry UI.
		 *
		 * @param string $settings Existing HTML.
		 * @param array  $value    Field config.
		 * @return string
		 */
		public function license_options_settings( $settings, $value ) {
			$license_data = ur_get_license_plan();
			$item_name    = ( is_object( $license_data ) && ! empty( $license_data->item_name ) ) ? $license_data->item_name : '';
			$expires_raw  = ( is_object( $license_data ) && ! empty( $license_data->expires ) ) ? $license_data->expires : '';

			$license_date_formatted = '';
			if ( 'lifetime' === $expires_raw ) {
				$license_date_formatted = __( 'Lifetime', 'user-registration' );
			} elseif ( '' !== $expires_raw ) {
				try {
					$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
					$expiry   = new DateTime( $expires_raw, new DateTimeZone( 'UTC' ) );
					$expiry->setTimezone( $timezone );
					$license_date_formatted = $expiry->format( 'jS F Y g:i A' );
				} catch ( Exception $e ) {
					$license_date_formatted = $expires_raw;
				}
			}

			$settings .= '<div class="user-registration-global-settings">';
			$settings .= '<label for="user-registration_license_plan">' . esc_html__( 'License Plan', 'user-registration' ) . '</label>';
			$settings .= '<div id="user-registration_license_plan" class="user-registration-global-settings--field">';
			$settings .= esc_html( $item_name );
			$settings .= '</div></div>';

			$settings .= '<div class="user-registration-global-settings">';
			$settings .= '<label for="user-registration_license_expiry">' . esc_html__( 'License Expiry Date', 'user-registration' ) . '</label>';
			$settings .= '<div id="user-registration_license_expiry" class="user-registration-global-settings--field">';
			$settings .= esc_html( $license_date_formatted );
			$settings .= '</div></div>';

			return $settings;
		}
		public function user_registration_license_setting_classes( $classes ) {
			$classes[] = 'license_setting_save_button';
			return $classes;
		}
	}
}

// Backward Compatibility.
return method_exists( 'UR_Settings_License', 'get_instance' ) ? UR_Settings_License::get_instance() : new UR_Settings_License();
