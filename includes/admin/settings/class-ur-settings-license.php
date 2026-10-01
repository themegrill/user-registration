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
		 * Mask a license key for display (••••ABCD).
		 *
		 * @param string $license_key Raw key.
		 * @return string
		 */
		public static function mask_license_key( $license_key ) {
			$license_key = (string) $license_key;
			$length      = strlen( $license_key );

			if ( $length <= 4 ) {
				return str_repeat( '•', $length );
			}

			return str_repeat( '•', $length - 4 ) . substr( $license_key, -4 );
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

			// When licensed: show masked key, change-key field, deactivate (confirmed), and status panel.
			if ( $license_key ) {
				$deactivate_url = wp_nonce_url(
					remove_query_arg(
						array( 'deactivated_license', 'activated_license', 'refreshed_license' ),
						add_query_arg( 'user-registration_deactivate_license', 1 )
					),
					'_ur_license_nonce'
				);

				$settings['sections']['license_options_settings']['settings'] = array(
					array(
						'title'    => __( 'Active License Key', 'user-registration' ),
						'desc'     => __( 'Currently activated license key (masked).', 'user-registration' ),
						'desc_tip' => true,
						'type'     => 'text',
						'id'       => 'user-registration_license_key_masked',
						'default'  => self::mask_license_key( $license_key ),
						'css'      => '',
						'custom_attributes' => array(
							'readonly' => 'readonly',
							'disabled' => 'disabled',
						),
					),
					array(
						'title'       => __( 'Change License Key', 'user-registration' ),
						'desc'        => __( 'Enter a new key and click Activate License to replace the current one without deactivating first.', 'user-registration' ),
						'id'          => 'user-registration_license_key_replace',
						'default'     => '',
						'type'        => 'text',
						'css'         => '',
						'desc_tip'    => true,
						'placeholder' => __( 'Enter a new license key', 'user-registration' ),
					),
					array(
						'id'     => 'ur_license_nonce',
						'action' => '_ur_license_nonce',
						'type'   => 'nonce',
					),
					array(
						'title'    => __( 'Deactivate License', 'user-registration' ),
						'desc'     => '',
						'desc_tip' => __( 'Deactivate the license of User Registration plugin', 'user-registration' ),
						'type'     => 'link',
						'id'       => 'user-registration_deactivate-license_key',
						'css'      => '',
						'buttons'  => array(
							array(
								'title'   => __( 'Deactivate License', 'user-registration' ),
								'href'    => $deactivate_url,
								'class'   => 'ur-button ur-button--destructive user_registration-deactivate-license-key ur-deactivate-license-confirm',
								'onclick' => 'return confirm( ' . wp_json_encode( self::get_deactivate_confirm_message() ) . ' );',
							),
						),
					),
					array(
						'type' => 'license_options',
						'id'   => 'user_registration_license_section_settings',
					),
				);
				// Keep save button so a replacement key can be activated without deactivating first.
			}
			return $settings;
		}
		public function user_registration_license_setting_label() {
			return esc_html__( 'Activate License', 'user-registration' );
		}

		/**
		 * Render plan / expiry / activations / re-check UI.
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
			$expiry_state_class     = '';
			$expiry_badge           = '';
			$renew_html             = '';

			if ( 'lifetime' === $expires_raw ) {
				$license_date_formatted = __( 'Lifetime', 'user-registration' );
			} elseif ( '' !== $expires_raw ) {
				try {
					$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
					$expiry   = new DateTime( $expires_raw, new DateTimeZone( 'UTC' ) );
					$expiry->setTimezone( $timezone );
					$license_date_formatted = $expiry->format( 'jS F Y g:i A' );

					$now  = new DateTime( 'now', $timezone );
					$soon = clone $now;
					$soon->modify( '+30 days' );

					if ( $expiry < $now ) {
						$expiry_state_class = 'urm-license-expiry--expired';
						$expiry_badge       = '<span class="urm-license-badge urm-license-badge--expired">' . esc_html__( 'Expired', 'user-registration' ) . '</span>';
					} elseif ( $expiry <= $soon ) {
						$expiry_state_class = 'urm-license-expiry--expiring';
						$expiry_badge       = '<span class="urm-license-badge urm-license-badge--expiring">' . esc_html__( 'Expiring soon', 'user-registration' ) . '</span>';
					}

					if ( $expiry < $now || $expiry <= $soon ) {
						$renew_url  = ur_utm_url(
							'https://wpuserregistration.com/pricing/',
							array(
								'source' => 'ur-license-setting',
								'medium' => 'renew-link',
							)
						);
						$renew_html = ' <a class="urm-license-renew-link" href="' . esc_url( $renew_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Renew license', 'user-registration' ) . '</a>';
					}
				} catch ( Exception $e ) {
					$license_date_formatted = $expires_raw;
				}
			}

			$activations_html = '';
			if ( is_object( $license_data ) && ( isset( $license_data->site_count ) || isset( $license_data->license_limit ) ) ) {
				$used  = isset( $license_data->site_count ) ? absint( $license_data->site_count ) : 0;
				$limit = isset( $license_data->license_limit ) ? absint( $license_data->license_limit ) : 0;
				if ( $limit > 0 ) {
					$activations_html = sprintf(
						/* translators: 1: sites used, 2: site limit */
						__( 'Used on %1$d of %2$d sites', 'user-registration' ),
						$used,
						$limit
					);
				} elseif ( $used > 0 ) {
					$activations_html = sprintf(
						/* translators: %d: sites used */
						__( 'Used on %d sites', 'user-registration' ),
						$used
					);
				}
			}

			$refresh_url = wp_nonce_url(
				remove_query_arg(
					array( 'deactivated_license', 'activated_license', 'refreshed_license' ),
					add_query_arg( 'user-registration_refresh_license', 1 )
				),
				'_ur_license_nonce'
			);

			$settings .= '<div class="user-registration-global-settings">';
			$settings .= '<label for="user-registration_license_plan">' . esc_html__( 'License Plan', 'user-registration' ) . '</label>';
			$settings .= '<div id="user-registration_license_plan" class="user-registration-global-settings--field">';
			$settings .= esc_html( $item_name );
			$settings .= '</div></div>';

			$settings .= '<div class="user-registration-global-settings">';
			$settings .= '<label for="user-registration_license_expiry">' . esc_html__( 'License Expiry Date', 'user-registration' ) . '</label>';
			$settings .= '<div id="user-registration_license_expiry" class="user-registration-global-settings--field ' . esc_attr( $expiry_state_class ) . '">';
			$settings .= esc_html( $license_date_formatted ) . $expiry_badge . $renew_html;
			$settings .= '</div></div>';

			if ( $activations_html ) {
				$settings .= '<div class="user-registration-global-settings">';
				$settings .= '<label for="user-registration_license_activations">' . esc_html__( 'Activations', 'user-registration' ) . '</label>';
				$settings .= '<div id="user-registration_license_activations" class="user-registration-global-settings--field">';
				$settings .= esc_html( $activations_html );
				$settings .= '</div></div>';
			}

			$settings .= '<div class="user-registration-global-settings">';
			$settings .= '<label for="user-registration_license_refresh">' . esc_html__( 'License Status', 'user-registration' ) . '</label>';
			$settings .= '<div id="user-registration_license_refresh" class="user-registration-global-settings--field">';
			$settings .= '<a href="' . esc_url( $refresh_url ) . '" class="button ur-button">' . esc_html__( 'Re-check license', 'user-registration' ) . '</a>';
			$settings .= '<p class="description">' . esc_html__( 'Refresh plan and expiry from the licensing server. Use after renewing or upgrading.', 'user-registration' ) . '</p>';
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
