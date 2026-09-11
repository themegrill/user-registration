<?php
/**
 * UserRegistration Email Settings
 *
 * @class    UR_Settings_Email
 * @version  1.0.0
 * @package  UserRegistration/Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'UR_Settings_Email' ) ) :
	/**
	 * UR_Settings_Email Class
	 */
	class UR_Settings_Email extends UR_Settings_Page {

		private static $_instance;

		/**
		 * Email notification classes.
		 *
		 * @var array
		 */
		public $emails = array();

		/**
		 * Setting Id.
		 *
		 * @var string
		 */
		public $id = 'email';

		/**
		 * Register hooks for submenus and section UI.
		 * @return void
		 */
		public function handle_hooks() {
			add_filter( "user_registration_get_sections_{$this->id}", array( $this, 'get_sections_callback' ), 1, 1 );
		}
		public static function get_instance() {
			if ( null === self::$_instance ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}

		/**
		 * Filter to provide sections submenu for scaffold settings.
		 */
		public function get_sections_callback( $sections ) {
			$sections['general']  = __( 'General', 'user-registration' );
			$sections['to-admin'] = __( 'To Admin', 'user-registration' );
			$sections['to-user']  = __( 'To User', 'user-registration' );

			// Health Checkup works with no license at all, so don't bury it
			// below Templates/Custom Email when those are locked behind an
			// inactive or free license — put it ahead of them instead.
			if ( self::has_active_email_premium_plan() ) {
				$sections['templates']      = __( 'Templates', 'user-registration' );
				$sections['custom-email']   = __( 'Custom Email', 'user-registration' );
				$sections['health-checkup'] = __( 'Health Checkup', 'user-registration' );
			} else {
				$sections['health-checkup'] = __( 'Health Checkup', 'user-registration' );
				$sections['templates']      = __( 'Templates', 'user-registration' );
				$sections['custom-email']   = __( 'Custom Email', 'user-registration' );
			}

			return $sections;
		}

		/**
		 * Whether the current license unlocks the premium email sections
		 * (Templates, Custom Email) — mirrors the same check
		 * UR_Settings_Page::output_sections() uses to decide whether to
		 * show the premium lock icon next to those sections.
		 *
		 * @return bool
		 */
		private static function has_active_email_premium_plan() {
			$premium_tabs = ur_premium_settings_tab();
			$premium_tab  = isset( $premium_tabs['email']['templates'] ) ? $premium_tabs['email']['templates'] : array();

			if ( empty( $premium_tab['plan'] ) ) {
				return true;
			}

			$license_data = ur_get_license_plan();
			$license_plan = ! empty( $license_data->item_plan ) ? $license_data->item_plan : false;
			$license_plan = trim( str_replace( 'lifetime', '', strtolower( $license_plan ) ) );

			if ( in_array( $license_plan, $premium_tab['plan'], true ) ) {
				return true;
			}

			if ( ! empty( $premium_tab['plugin'] ) && file_exists( WP_PLUGIN_DIR . '/' . $premium_tab['plugin'] ) && is_plugin_active( $premium_tab['plugin'] . '/' . $premium_tab['plugin'] . '.php' ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Constructor.
		 */
		public function __construct() {

			$this->id    = 'email';
			$this->label = __( 'Emails', 'user-registration' );

			parent::__construct();
			$this->handle_hooks();

			add_filter(
				'user_registration_admin_field_to_admin_email_notification',
				array(
					$this,
					'email_notification_setting',
				),
				10,
				2
			);
			add_filter(
				'user_registration_admin_field_to_user_email_notification',
				array(
					$this,
					'email_notification_setting',
				),
				10,
				2
			);
			$this->initialize_email_classes();

			$email_content_default_values = array();
			foreach ( $this->emails as $key => $email ) {
				$method_name = 'ur_get_' . $email->id;
				//for membership, the naming convention is different.
				if ( ! method_exists( $email, $method_name ) ) {
					$method_name = 'user_registration_get_' . $email->id;
				}
				$key             = strtolower( $key );
				$default_content = method_exists( $email, $method_name ) ? $email->$method_name() : '';

				// Unwrap email content for editor display (remove wrapper HTML and style tags).
				if ( function_exists( 'ur_unwrap_email_body_content' ) && ! empty( $default_content ) ) {
					$default_content = ur_unwrap_email_body_content( $default_content );
				}

				$email_content_default_values[ $key ] = $default_content;
			}
			wp_localize_script(
				'user-registration-settings',
				'user_registration_email_settings',
				$email_content_default_values,
			);
		}

		/**
		 * initialize_email_classes
		 *
		 * @return void
		 */
		public function initialize_email_classes() {
			$email_classes = array(
				'UR_Settings_Admin_Email',
				'UR_Settings_Approval_Link_Email',
				'UR_Settings_Awaiting_Admin_Approval_Email',
				'UR_Settings_Email_Confirmation',
				'UR_Settings_Successfully_Registered_Email',
				'UR_Settings_Registration_Denied_Email',
				'UR_Settings_Registration_Pending_Email',
				'UR_Settings_Reset_Password_Email',
				'UR_Settings_Profile_Details_Changed_Email',
				'UR_Settings_Profile_Details_Updated_Email',
				'UR_Settings_Confirm_Email_Address_Change_Email',
			);

			if ( ur_check_module_activation( 'membership' ) || ur_check_module_activation( 'payments' ) || is_plugin_active( 'user-registration-stripe/user-registration-stripe.php' ) || is_plugin_active( 'user-registration-authorize-net/user-registration-authorize-net.php' ) ) {
				$email_classes = array_merge(
					$email_classes,
					array(
						'UR_Settings_Payment_Success_Email',
						'UR_Settings_Payment_Success_Admin_Email',
					)
				);

			}

			foreach ( $email_classes as $class ) {
				$this->emails[ $class ] = include 'emails/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
			}

			/**
			 * Filter to modify the email classes accordingly.
			 *
			 * @param class Email classes to be included.
			 */
			$this->emails = apply_filters( 'user_registration_email_classes', $this->emails );
		}

		/**
		 * Get settings
		 *
		 * @return array
		 */
		public function get_settings() {

			/**
			 * Filter to add the options on settings.
			 *
			 * @param array Options to be enlisted.
			 */
			$settings = apply_filters(
				'user_registration_email_settings',
				array(
					'title'    => '',
					'sections' => array(
						'general_options' => array(
							'title'    => __( 'General', 'user-registration' ),
							'type'     => 'card',
							'desc'     => '',
							'settings' => array(
								array(
									'title'    => __( 'Disable emails', 'user-registration' ),
									'desc'     => __( 'Disable all emails sent after registration.', 'user-registration' ),
									'id'       => 'user_registration_email_setting_disable_email',
									'default'  => 'no',
									'type'     => 'toggle',
									'autoload' => false,
								),
								array(
									'title'       => __( '"From" name', 'user-registration' ),
									'desc'        => __( 'How the sender name appears in outgoing user registration emails. Leave blank to use the site title.', 'user-registration' ),
									'id'          => 'user_registration_email_from_name',
									'type'        => 'text',
									// The description is a tooltip, so the placeholder is the
									// only thing an admin reliably reads — it has to name the
									// fallback rather than just show a value.
									'placeholder' => sprintf(
										/* translators: %s: site title */
										__( 'Defaults to the site title (%s)', 'user-registration' ),
										get_bloginfo( 'name', 'display' )
									),
									'css'         => 'min-width:300px;',
									'default'     => esc_attr( get_bloginfo( 'name', 'display' ) ),
									'autoload'    => false,
									'desc_tip'    => true,
								),

								array(
									'title'             => __( '"From" address', 'user-registration' ),
									'desc'              => __( 'How the sender email appears in outgoing user registration emails. Leave blank to use the site admin email.', 'user-registration' ),
									'id'                => 'user_registration_email_from_address',
									'type'              => 'email',
									'placeholder'       => sprintf(
										/* translators: %s: site admin email address */
										__( 'Defaults to the site admin email (%s)', 'user-registration' ),
										get_option( 'admin_email' )
									),
									'custom_attributes' => array(
										'multiple' => 'multiple',
									),
									'css'               => 'min-width:300px;',
									'default'           => get_option( 'admin_email' ),
									'autoload'          => false,
									'desc_tip'          => true,
								),
								array(
									'title'             => __( 'Send Test Email', 'user-registration' ),
									'desc'              => __( 'Enter email address where test email will be sent.', 'user-registration' ),
									'id'                => 'user_registration_email_send_to',
									'type'              => 'email',
									'custom_attributes' => array(
										'multiple' => 'multiple',
									),
									'css'               => 'min-width:300px;',
									'default'           => get_option( 'admin_email' ),
									'autoload'          => false,
									'desc_tip'          => true,
								),
								array(
									'id'      => 'user_registration_email_test',
									'type'    => 'link',
									'align'   => 'end',
									'css'     => 'min-width:90px;',
									'buttons' => array(
										array(
											'title' => __( 'Send Email', 'user-registration' ),
											'href'  => '#',
											'class' => 'urm-btn-comp-v7 user_registration_send_email_test',
										),
										array(
											'title' => __( 'Health Checkup', 'user-registration' ),
											'href'  => esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=email&section=health-checkup' ) ),
											'class' => 'urm-btn-comp-v7 user_registration_email_health_checkup',
										),
									),
								),
							),
						),
					),
				)
			);

			/**
			 * Filter to get the settings.
			 *
			 * @param array $settings Email Setting options to be enlisted.
			 */
			return apply_filters( 'user_registration_get_email_settings_' . $this->id, $settings );
		}

		public function get_to_admin_email_list_section() {
			/**
			 * Filter to add the options on settings.
			 *
			 * @param array Options to be enlisted.
			 */
			$settings = apply_filters(
				'user_registration_to_admin_email_list_section_settings',
				array(
					'title'    => '',
					'sections' => array(
						'email_notification_settings' => array(
							'title'    => __( 'To Admin', 'user-registration' ),
							'type'     => 'card',
							'button'   => array(
								'button_link' => 'https://docs.wpuserregistration.com/docs/smart-tags/',
								'button_text' => __( 'Smart Tags Reference', 'user-registration' ),
							),
							'settings' => array(
								array(
									'type' => 'to_admin_email_notification',
									'id'   => 'user_registration_to_admin_email_notification_settings',
								),
							),
						),
					),
				)
			);

			/**
			 * Filter to get the settings.
			 *
			 * @param array $settings Email Setting options to be enlisted.
			 */
			return apply_filters( 'user_registration_get_to_admin_email_list_section_settings_' . $this->id, $settings );
		}

		public function get_to_user_email_list_section() {
			/**
			 * Filter to add the options on settings.
			 *
			 * @param array Options to be enlisted.
			 */
			$settings = apply_filters(
				'user_registration_to_user_email_list_section_settings',
				array(
					'title'    => '',
					'sections' => array(
						'email_notification_settings' => array(
							'title'    => __( 'To User', 'user-registration' ),
							'type'     => 'card',
							'button'   => array(
								'button_link' => 'https://docs.wpuserregistration.com/docs/smart-tags/',
								'button_text' => __( 'Smart Tags Reference', 'user-registration' ),
							),
							'settings' => array(
								array(
									'type' => 'to_user_email_notification',
									'id'   => 'user_registration_to_user_email_notification_settings',
								),
							),
						),
					),
				)
			);

			/**
			 * Filter to get the settings.
			 *
			 * @param array $settings Email Setting options to be enlisted.
			 */
			return apply_filters( 'user_registration_get_to_user_email_list_section_settings_' . $this->id, $settings );
		}

		/**
		 * Retrieve Email Data.
		 *
		 * @return array Emails.
		 */
		public function get_emails() {
			return $this->emails;
		}

		/**
		 * Email Notification Settings.
		 *
		 * @param string $settings Settings.
		 * @param mixed $value Value.
		 */
		public function email_notification_setting( $settings, $value ) {
			$type_array = explode( '_email_notification', $value['type'] );
			$type       = ! empty( $type_array ) ? ( str_replace( 'to_', '', $type_array[0] ) ) : '';

			$settings .= '<tr valign="top">';
			$settings .= '<td class="ur_emails_wrapper" colspan="2">';
			$settings .= '<table class="ur_emails widefat" cellspacing="0">';
			$settings .= '<thead>';
			$settings .= '<tr>';

			/**
			 * Filter to modify the user registration email setting columns.
			 *
			 * @param array Settings to be included on column.
			 */
			$columns = apply_filters(
				'user_registration_email_setting_columns',
				array(
					'name'    => __( 'Email', 'user-registration' ),
					'status'  => __( 'Status', 'user-registration' ),
					'preview' => __( 'Preview', 'user-registration' ),
					'actions' => __( 'Configure', 'user-registration' ),
				)
			);

			foreach ( $columns as $key => $column ) {
				$settings .= '<th style="padding-left:15px" class="ur-email-settings-table-' . esc_attr( $key ) . '">' . esc_html( $column ) . '</th>';
			}
			$settings .= '</tr>';
			$settings .= '</thead>';
			$settings .= '<tbody>';

			$emails = $this->get_emails();
			foreach ( $emails as $email ) {
				if ( strtolower( $email->receiver ) !== $type ) {
					continue;
				}
				$status = ! ur_string_to_bool( get_option( 'user_registration_email_setting_disable_email', false ) ) ? ur_string_to_bool( get_option( 'user_registration_enable_' . $email->id, true ) ) : false;

				$settings .= '<tr><td class="ur-email-settings-table">';
				$settings .= '<a href="' . esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=email&section=ur_settings_' . $email->id . '' ) ) .
							'">' . esc_html( $email->title ) . '</a>';
				$settings .= ur_help_tip( $email->description );
				$settings .= '</td>';
				$settings .= '<td class="ur-email-settings-table">';
				$label     = '<div class="ur-toggle-section"><span class="user-registration-toggle-form user-registration-email-status-toggle" ><input type="checkbox" name="email_status" id="' . esc_attr( $email->id ) . '"' . ( $status ? "checked='checked'" : '' ) . '"/><span class="slider round"></span></span></div>';
				$settings .= '<label class="ur-email-status" style="' . ( $status ? 'color:green;font-weight:500;' : 'color:red;font-weight:500;' ) . '">';
				$settings .= $label;
				$settings .= '</label>';
				$settings .= '</td>';
				$settings .= '<td class="ur-email-settings-table">';
				$settings .= '<a class="button tips user-registration-email-preview urm-btn-comp-v7 " rel="noreferrer noopener" target="__blank" data-tip="' . esc_attr__( 'Preview', 'user-registration' ) . '" href="' . esc_url(
					add_query_arg(
						array(
							'ur_email_preview' => $email->id,
						),
						home_url()
					)
				) . '"><span class="dashicons dashicons-visibility"></span></a>';
				$settings .= '</td>';
				$settings .= '<td class="ur-email-settings-table">';
				$settings .= '<a class="button tips" data-tip="' . esc_attr__( 'Configure', 'user-registration' ) . '" href="' . esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=email&section=ur_settings_' . $email->id . '' ) ) . '"><span class="dashicons dashicons-admin-generic"></span> </a>';
				$settings .= '</td>';
				$settings .= '</tr>';
			}

			$settings .= '</tbody>';
			$settings .= '</table>';
			$settings .= '</td>';
			$settings .= '</tr>';

			return $settings;
		}

		/**
		 * Save Email Settings.
		 */
		public function save() {
			global $current_section;
			$emails = $this->get_emails();

			foreach ( $emails as $email ) {
				if ( 'ur_settings_' . $email->id . '' === $current_section ) {
					$settings = new $email();
					$settings = $settings->get_settings();
				}
			}

			$settings = isset( $settings ) ? $settings : $this->get_settings();

			UR_Admin_Settings::save_fields( $settings );
		}

		/**
		 * Output the settings.
		 */
		public function output() {
			global $current_section;
			$emails   = $this->get_emails();
			$settings = array(); // in case of settings page.
			foreach ( $emails as $email ) {
				if ( 'ur_settings_' . $email->id === $current_section ) {
					$settings = new $email();
					$settings = $settings->get_settings();
				}
			}

			switch ( $current_section ) {
				case 'health-checkup':
					// UR_Admin_Email_Checkup redirects this section to the full-screen
					// run on admin_init. This is only reached if that could not fire.
					printf(
						'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
						esc_url( UR_Admin_Email_Checkup::url() ),
						esc_html__( 'Open the Email Delivery Checkup', 'user-registration' )
					);
					return;
				case 'to-admin':
					$settings = $this->get_to_admin_email_list_section();
					break;
				case 'to-user':
					$settings = $this->get_to_user_email_list_section();
					break;
				case 'general':
					$settings = $this->get_settings();
					break;
				case 'templates':
					$settings = $this->upgrade_to_pro_setting();
					break;

				case 'custom-email':
					if ( ur_check_module_activation( 'custom-email' ) ) {
						$settings = apply_filters( 'user_registration_get_email_settings_email', array() );
					} else {
						$settings = $this->upgrade_to_pro_setting();
					}
					break;
				default:
					if ( ur_check_module_activation( 'custom-email' ) ) {
						if ( ! empty( $current_section ) && strpos( $current_section, 'ur_settings_custom_email_' ) === 0 ) {
							$settings = apply_filters( 'user_registration_get_email_settings_email', array() );
						}
					}
					break;
			}

			UR_Admin_Settings::output_fields( $settings );
		}
	}
endif;

//Backward Compatibility.
return method_exists( 'UR_Settings_Email', 'get_instance' ) ? UR_Settings_Email::get_instance() : new UR_Settings_Email();
