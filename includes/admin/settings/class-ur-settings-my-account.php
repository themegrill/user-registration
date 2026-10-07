<?php
/**
 * Class UR_Settings_My_Account
 *
 * Handles the my_account related settings for the User Registration & Membership plugin.
 *
 * This class is responsible for:
 *
 * @package   UserRegistration\Admin
 * @version   5.0.0
 * @since     5.0.0
 */
if ( ! class_exists( 'UR_Settings_My_Account' ) ) {
	/**
	 * UR_Settings_My_Account Class
	 */
	class UR_Settings_My_Account extends UR_Settings_Page {
		private static $_instance = null;
		/**
		 * Constructor.
		 */
		private function __construct() {
			$this->id    = 'my_account';
			$this->label = __( 'My Account', 'user-registration' );
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
			add_filter( "user_registration_get_sections_{$this->id}", array( $this, 'get_sections_callback' ), 1, 1 );
			add_filter( "user_registration_get_settings_{$this->id}", array( $this, 'get_settings_callback' ), 1, 1 );
		}
		/**
		 * Filter to provide sections submenu for my_account settings.
		 */
		public function get_sections_callback( $sections ) {
			$sections['general']              = __( 'General', 'user-registration' );
			$sections['customize-my-account'] = __( 'Customize My Account', 'user-registration' );
			$sections['endpoint']             = __( 'Endpoints', 'user-registration' );

			return $sections;
		}
		/**
		 * Filter to provide sections UI for my_account settings.
		 */
		public function get_settings_callback( $settings ) {
			global $current_section;
			if ( 'general' === $current_section ) {
				return $this->get_general_settings();
			}
			if ( 'endpoint' === $current_section ) {
				return $this->get_endpoint_settings();
			}
			if ( 'customize-my-account' === $current_section ) {
				return $this->upgrade_to_pro_setting();
			}
		}
		public function get_general_settings() {

			$all_roles = ur_get_default_admin_roles();

			$all_roles_except_admin = $all_roles;

			unset( $all_roles_except_admin['administrator'] );

			/**
			 * Filter to add the options settings.
			 *
			 * @param array Options to be enlisted.
			 */
			$settings = array(
				'title'    => '',
				'sections' => array(
					'my_account_options' => array(
						'title'    => __( 'General', 'user-registration' ),
						'type'     => 'card',
						'desc'     => ur_string_to_bool( get_option( 'urm_is_new_installation', '' ) ) ? '' : sprintf(
							__( '<strong>My Account page setting has moved.</strong> Configure your my account page <a href="%s">here</a>.', 'user-registration' ),
							admin_url( 'admin.php?page=user-registration-settings&tab=general&section=pages' )
						),
						'settings' => array(
							array(
								'title'              => __( 'Layout', 'user-registration' ),
								'desc'               => __( 'This option lets you choose the layout for the user registration my account tabs.', 'user-registration' ),
								'id'                 => 'user_registration_my_account_layout',
								'default'            => 'vertical',
								'type'               => 'radio-group',
								'css'                => '',
								'desc_tip'           => true,
								'options'            => array(
									'horizontal' => __( 'Horizontal', 'user-registration' ),
									'vertical'   => __( 'Vertical', 'user-registration' ),
								),
								'radio-group-images' => array(
									'horizontal' => UR()->plugin_url() . '/assets/images/onboard-icons/horizontal.png',
									'vertical'   => UR()->plugin_url() . '/assets/images/onboard-icons/vertical.png',
								),
							),
							array(
								'title'    => __( 'Ajax Submission on Edit Profile', 'user-registration' ),
								'desc'     => __( 'Check to enable ajax form submission on edit profile i.e. saves profile details on save button click without reloading the page.', 'user-registration' ),
								'id'       => 'user_registration_ajax_form_submission_on_edit_profile',
								'type'     => 'toggle',
								'desc_tip' => true,
								'css'      => '',
								'default'  => 'no',
							),
							array(
								'title'    => __( 'Disable Profile Picture', 'user-registration' ),
								'desc'     => __( 'Check to disable profile picture in edit profile page.', 'user-registration' ),
								'id'       => 'user_registration_disable_profile_picture',
								'type'     => 'toggle',
								'desc_tip' => true,
								'css'      => '',
								'default'  => 'no',
							),
							array(
								'title'    => __( 'Sync Profile picture', 'user-registration' ),
								'desc'     => __( 'Check to enable if you want to display profile picture on edit profile if form have profile field', 'user-registration' ),
								'id'       => 'user_registration_sync_profile_picture',
								'type'     => 'toggle',
								'desc_tip' => true,
								'css'      => '',
								'default'  => '',
							),
						),
					),
				),
			);
			/**
			 * Filter to get the settings.
			 *
			 * @param array $settings Setting options to be enlisted.
			 */
			return apply_filters( 'user_registration_' . $this->id . '_general_settings', $settings );
		}
		public function get_endpoint_settings() {

			$all_roles = ur_get_default_admin_roles();

			$all_roles_except_admin = $all_roles;

			unset( $all_roles_except_admin['administrator'] );

			/**
			 * Filter to add the options settings.
			 *
			 * @param array Options to be enlisted.
			 */
			$settings = array(
				'title'    => '',
				'sections' => array(
					'endpoint_options' => array(
						'title'    => __( 'Endpoints', 'user-registration' ),
						'type'     => 'card',
						'desc'     => '<strong>' . __( 'Endpoints: ', 'user-registration' ) . '</strong>' . __( 'Endpoints are appended to your page URLs to handle specific actions on the accounts pages. They should be unique and can be left blank to disable the endpoint.', 'user-registration' ),
						'settings' => array(
							array(
								'title'    => __( 'Edit Profile', 'user-registration' ),
								'desc'     => __( 'Endpoint for the "My account &rarr; Edit profile" page.', 'user-registration' ),
								'id'       => 'user_registration_myaccount_edit_profile_endpoint',
								'type'     => 'text',
								'default'  => 'edit-profile',
								'desc_tip' => true,
							),
							array(
								'title'    => __( 'Change Password', 'user-registration' ),
								'desc'     => __( 'Endpoint for the "My account &rarr; Change Password" page.', 'user-registration' ),
								'id'       => 'user_registration_myaccount_change_password_endpoint',
								'type'     => 'text',
								'default'  => 'edit-password',
								'desc_tip' => true,
							),
							array(
								'title'    => __( 'User Logout', 'user-registration' ),
								'desc'     => __( 'Endpoint for triggering logout. You can add this to your menus via a custom link: yoursite.com/?user-logout=true', 'user-registration' ),
								'id'       => 'user_registration_logout_endpoint',
								'type'     => 'text',
								'default'  => 'user-logout',
								'desc_tip' => true,
							),
						),
					),
				),
			);
			/**
			 * Filter to get the settings.
			 *
			 * @param array $settings Setting options to be enlisted.
			 */
			return apply_filters( 'user_registration_' . $this->id . '_endpoint_settings', $settings );
		}
	}
}
/*
Existing Filters have been deprecated and removed where not applicable, I have made appropriate changes to addons that rely on those filters.
and follows the following naming convention for new setting sections.
"user_registration_{$current_tab}_{$current_section}_settings"
*/

// Backward Compatibility.
return method_exists( 'UR_Settings_My_Account', 'get_instance' ) ? UR_Settings_My_Account::get_instance() : new UR_Settings_My_Account();
