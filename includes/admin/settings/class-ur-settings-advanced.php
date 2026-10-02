<?php
/**
 * Class UR_Settings_Advanced
 *
 * Handles the advanced related settings for the User Registration & Membership plugin.
 *
 * This class is responsible for:
 *
 * @package   UserRegistration\Admin
 * @version   5.0.0
 * @since     5.0.0
 */
if ( ! class_exists( 'UR_Settings_Advanced' ) ) {
	/**
	 * UR_Settings_Advanced Class
	 */
	class UR_Settings_Advanced extends UR_Settings_Page {
		private static $_instance = null;
		/**
		 * Constructor.
		 */
		private function __construct() {
			$this->id    = 'advanced';
			$this->label = __( 'Advanced', 'user-registration' );
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

			// handle settings HTML UI via action hook.
			// add_action( "user_registration_settings_{$this->id}", array( $this, 'output_import_export' ) );
		}

		public function output_import_export() {
			global $current_section;
			if ( 'import-export' === $current_section ) {
				// remove save button for import/export.
				add_filter( 'user_registration_settings_hide_save_button', '__return_true' );

				$users_settings = UR_Admin_Export_Users::output();
				$forms_settings = UR_Admin_Import_Export_Forms::output();
				UR_Admin_settings::output_fields( $users_settings );
				UR_Admin_Settings::output_fields( $forms_settings );
			}
		}

		/**
		 * Filter to provide sections submenu for advanced settings.
		 */
		public function get_sections_callback( $sections ) {
			$sections['import-export']   = __( 'Import/Export', 'user-registration' );
			$sections['plugin-deletion'] = __( 'Plugin Deletion', 'user-registration' );
			$sections['others']          = __( 'Others', 'user-registration' );
			return $sections;
		}

		/**
		 * Filter to provide sections UI for advanced settings.
		 */
		public function get_settings_callback( $settings ) {
			global $current_section;

			if ( 'plugin-deletion' === $current_section ) {
				return $this->get_plugin_deletion_settings();
			}
			if ( 'others' === $current_section ) {
				return $this->get_others_settings();
			}
			return $settings;
		}
		public function get_plugin_deletion_settings() {
			return apply_filters(
				'user_registration_settings_advanced_plugin-deletion',
				array(
					'title'    => '',
					'sections' => array(
						'advanced_settings' => array(
							'title'    => __( 'Plugin Deletion', 'user-registration' ),
							'type'     => 'card',
							'desc'     => '',
							'settings' => array(
								array(
									'title'    => __( 'Uninstall User Registration & Membership', 'user-registration' ),
									'desc'     => __( '<strong>Heads Up!</strong> Check this if you would like to remove ALL User Registration & Membership data upon plugin deletion.', 'user-registration' ),
									'id'       => 'user_registration_general_setting_uninstall_option',
									'type'     => 'toggle',
									'desc_tip' => 'All user registration & membership forms, settings and users metadata will be deleted upon plugin uninstallation.',
									'css'      => '',
									'default'  => 'false',
								),
							),
						),
					),
				)
			);
		}

		public function get_others_settings() {
			/**
			 * Modifies the others section inside the Advanced settting.
			 *
			 * @param $settings array settings section.
			 */
			return apply_filters(
				'user_registration_settings_advanced_others',
				array(
					'title'    => '',
					'sections' => array(
						'advanced_settings' => array(
							'title'    => __( 'Others', 'user-registration' ),
							'type'     => 'card',
							'desc'     => '',
							'settings' => array(
								array(
									'title'   => __( 'Allow Usage Tracking', 'user-registration' ),
									'desc'    => __( 'Help us improve the plugin\'s features by sharing <a href="https://docs.wpuserregistration.com/global-settings/advanced-settings/#allow-usage-tracking" rel="noreferrer noopener" target="_blank">non-sensitive plugin data</a> with us.', 'user-registration' ),
									'id'      => 'user_registration_allow_usage_tracking',
									'type'    => 'toggle',
									'css'     => '',
									'default' => 'no',
								),
							),
						),
					),
				)
			);
		}
	}
}

// Backward Compatibility.
return method_exists( 'UR_Settings_Advanced', 'get_instance' ) ? UR_Settings_Advanced::get_instance() : new UR_Settings_Advanced();
