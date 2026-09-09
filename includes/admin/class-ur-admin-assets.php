<?php
/**
 * UserRegistration Admin Assets
 *
 * Load Admin Assets.
 *
 * @class    UR_Admin_Assets
 * @version  1.0.0
 * @package  UserRegistration/Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * UR_Admin_Assets Class
 */
class UR_Admin_Assets {

	/**
	 * Hook in tabs.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
	}

	/**
	 * Enqueue styles.
	 */
	public function admin_styles() {
		global $wp_scripts;

		$screen         = get_current_screen();
		$screen_id      = $screen ? $screen->id : '';
		$jquery_version = isset( $wp_scripts->registered['jquery-ui-core']->ver ) ? $wp_scripts->registered['jquery-ui-core']->ver : '1.9.2';

		// Register admin styles.
		wp_register_style( 'user-registration-metabox', UR()->plugin_url() . '/assets/css/metabox.css', array(), UR_VERSION );
		wp_register_style( 'user-registration-form-modal-css', UR()->plugin_url() . '/assets/css/form-modal.css', array(), UR_VERSION );

		wp_register_style( 'user-registration-settings', UR()->plugin_url() . '/assets/css/settings.css', array( 'nav-menus' ), UR_VERSION );
		wp_register_style( 'jquery-ui-style', UR()->plugin_url() . '/assets/css/jquery-ui/jq-smoothness.css', array(), $jquery_version );
		wp_register_style( 'flatpickr', UR()->plugin_url() . '/assets/css/flatpickr/flatpickr.min.css', array(), UR_VERSION );
		wp_register_style( 'perfect-scrollbar', UR()->plugin_url() . '/assets/css/perfect-scrollbar/perfect-scrollbar.css', array(), UR_VERSION );
		wp_register_style( 'sweetalert2', UR()->plugin_url() . '/assets/css/sweetalert2/sweetalert2.min.css', array(), UR_VERSION );

		wp_register_style( 'user-registration-dashboard-widget', UR()->plugin_url() . '/assets/css/dashboard.css', array(), UR_VERSION );

		wp_register_style( 'ur-notice', UR()->plugin_url() . '/assets/css/ur-notice.css', array(), UR_VERSION );

		wp_register_style( 'jquery-confirm-style', UR()->plugin_url() . '/assets/css/jquery-confirm/jquery-confirm.css', array(), $jquery_version );

		wp_register_style( 'tooltipster', UR()->plugin_url() . '/assets/css/tooltipster/tooltipster.bundle.min.css', array(), UR_VERSION );
		wp_register_style( 'tooltipster-borderless-theme', UR()->plugin_url() . '/assets/css/tooltipster/tooltipster-sideTip-borderless.min.css', array(), UR_VERSION );
		wp_register_style( 'ur-toast', UR()->plugin_url() . '/assets/css/ur-toast.css', array(), UR_VERSION );

		// Add RTL support for admin styles.
		wp_style_add_data( 'user-registration-menu', 'rtl', 'replace' );
		wp_style_add_data( 'user-registration-admin', 'rtl', 'replace' );

		// Sitewide menu CSS.

		wp_enqueue_style( 'ur-notice' );
		wp_register_style( 'user-registration-menu', UR()->plugin_url() . '/assets/css/menu.css', array(), UR_VERSION );
		wp_enqueue_style( 'user-registration-menu' );

		wp_register_style( 'user-registration-admin', UR()->plugin_url() . '/assets/css/admin.css', array( 'nav-menus', 'wp-color-picker' ), UR_VERSION );

		// Admin styles for UR pages only.
		if ( in_array( $screen_id, ur_get_screen_ids(), true ) ) {
			wp_enqueue_style( 'user-registration-admin' );

			if ( strpos( $screen_id, 'user-registration-settings' ) ) {
				wp_enqueue_style( 'user-registration-settings' );
			}
			wp_enqueue_style( 'user-registration-menu' );
			wp_enqueue_style( 'jquery-ui-style' );
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_style( 'perfect-scrollbar' );
			wp_enqueue_style( 'sweetalert2' );
			wp_enqueue_style( 'jquery-confirm-style' );
			wp_enqueue_style( 'tooltipster' );
			wp_enqueue_style( 'tooltipster-borderless-theme' );

			wp_enqueue_style( 'user-registration-metabox' );
			wp_enqueue_style( 'user-registration-form-modal-css' );

			wp_enqueue_style( 'select2', UR()->plugin_url() . '/assets/css/select2/select2.css', array(), UR_VERSION );
		}
		// Enqueue flatpickr on user profile screen.
		if ( 'user-edit' === $screen_id || 'profile' === $screen_id || 'user-registration-membership_page_add-new-registration' === $screen_id ) {
			wp_enqueue_style( 'flatpickr' );
		}

		// Enqueue dashboard widget CSS in dashboard screen only.
		if ( 'dashboard' === $screen_id ) {
			wp_enqueue_style( 'user-registration-dashboard-widget' );
		}
	}

	/**
	 * Enqueue scripts.
	 */
	public function admin_scripts() {

		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';
		$suffix    = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// Register Scripts.
		wp_register_script(
			'user-registration-admin',
			UR()->plugin_url() . '/assets/js/admin/admin' . $suffix . '.js',
			array(
				'jquery',
				'selectWoo',
				'wp-color-picker',
				'jquery-blockui',
				'jquery-ui-sortable',
				'jquery-ui-widget',
				'jquery-ui-core',
				'jquery-ui-tabs',
				'jquery-ui-draggable',
				'jquery-ui-droppable',
				'ur-backbone-modal',
				'ur-enhanced-select',
				'perfect-scrollbar',
				'sweetalert2',
				'tooltipster',
				'user-registration-scroll-ui-js',
			),
			UR_VERSION,
			false
		);

		if ( 'user-registration-membership_page_user-registration-login-forms' !== $screen_id ) {

			wp_register_script(
				'user-registration-form-builder',
				UR()->plugin_url() . '/assets/js/admin/form-builder' . $suffix . '.js',
				array(
					'jquery',
					'selectWoo',
					'wp-color-picker',
					'jquery-blockui',
					'jquery-ui-sortable',
					'jquery-ui-widget',
					'jquery-ui-core',
					'jquery-ui-tabs',
					'jquery-ui-draggable',
					'jquery-ui-droppable',
					'ur-backbone-modal',
					'ur-enhanced-select',
					'perfect-scrollbar',
					'sweetalert2',
					'tooltipster',
					'user-registration-scroll-ui-js',
					'flatpickr',
				),
				UR_VERSION,
				false
			);
			wp_register_script(
				'user-registration-form-settings',
				UR()->plugin_url() . '/assets/js/admin/form-settings' . $suffix . '.js',
				array(
					'user-registration-admin',
					'user-registration-form-builder',
				),
				UR_VERSION,
				false
			);
		}

		wp_register_script( 'popper', UR()->plugin_url() . "/assets/js/popper/popper$suffix.js", [], UR_VERSION, true );
		wp_register_script( 'tippy', UR()->plugin_url() . "/assets/js/tippy/tippy$suffix.js", [ 'popper' ], UR_VERSION, true );

		wp_register_script( 'jquery-blockui', UR()->plugin_url() . '/assets/js/jquery-blockui/jquery.blockUI' . $suffix . '.js', array( 'jquery' ), UR_VERSION, true );
		wp_register_script( 'tooltipster', UR()->plugin_url() . '/assets/js/tooltipster/tooltipster.bundle' . $suffix . '.js', array( 'jquery' ), UR_VERSION, true );
		wp_register_script( 'jquery-confirm', UR()->plugin_url() . '/assets/js/jquery-confirm/jquery-confirm' . $suffix . '.js', array( 'jquery' ), UR_VERSION, true );
		wp_register_script(
			'ur-backbone-modal',
			UR()->plugin_url() . '/assets/js/admin/backbone-modal' . $suffix . '.js',
			array(
				'underscore',
				'backbone',
				'wp-util',
			),
			UR_VERSION,
			false
		);

		wp_register_script( 'user-registration-scroll-ui-js', UR()->plugin_url() . '/assets/js/ur-components/scroll-ui.js', 'jquery', UR_VERSION, false );

		wp_register_script( 'user-registration-form-modal-js', UR()->plugin_url() . '/assets/js/admin/form-modal' . $suffix . '.js', 'jquery', UR_VERSION, false );
		wp_register_script( 'user-registration-dashboard-widget-js', UR()->plugin_url() . '/assets/js/admin/dashboard-widget' . $suffix . '.js', 'jquery', UR_VERSION, false );
		wp_register_script( 'selectWoo', UR()->plugin_url() . '/assets/js/selectWoo/selectWoo.full' . $suffix . '.js', array( 'jquery' ), '5.0.0', false );
		wp_register_script(
			'wp-color-picker-alpha',
			UR()->plugin_url() . '/assets/js/wp-color-picker/wp-color-picker-alpha' . $suffix . '.js',
			array( 'wp-color-picker' ),
			UR_VERSION,
			false
		);
		wp_register_script(
			'ur-enhanced-select',
			UR()->plugin_url() . '/assets/js/admin/enhanced-select' . $suffix . '.js',
			array(
				'jquery',
				'selectWoo',
			),
			UR_VERSION,
			false
		);

		wp_register_script( 'flatpickr', UR()->plugin_url() . '/assets/js/flatpickr/flatpickr.min.js', array( 'jquery' ), UR_VERSION, false );
		wp_register_script( 'perfect-scrollbar', UR()->plugin_url() . '/assets/js/perfect-scrollbar/perfect-scrollbar.min.js', array( 'jquery' ), UR_VERSION, false );
		wp_register_script( 'ur-chartjs', UR()->plugin_url() . '/assets/js/chartjs/Chart.min.js', array( 'jquery' ), UR_VERSION, false );
		wp_register_script( 'sweetalert2', UR()->plugin_url() . '/assets/js/sweetalert2/sweetalert2.min.js', array( 'jquery' ), UR_VERSION, false );
		wp_register_script( 'ur-setup', UR()->plugin_url() . '/assets/js/admin/ur-setup' . $suffix . '.js', array( 'jquery', 'sweetalert2', 'updates', 'wp-i18n' ), UR_VERSION, false );

		wp_localize_script(
			'ur-setup',
			'ur_setup_params',
			array(
				'ajax_url'                     => admin_url( 'admin-ajax.php' ),
				'rest_url'                     => esc_url_raw( rest_url( 'user-registration/v1/' ) ),
				'rest_nonce'                   => wp_create_nonce( 'wp_rest' ),
				'create_form_nonce'            => wp_create_nonce( 'user_registration_create_form' ),
				'template_licence_check_nonce' => wp_create_nonce( 'user_registration_template_licence_check' ),
				'captcha_setup_check_nonce'    => wp_create_nonce( 'user_registration_captcha_setup_check' ),
				'i18n_form_name'               => esc_html__( 'Give it a name.', 'user-registration' ),
				'i18n_form_error_name'         => esc_html__( 'You must provide a Form name', 'user-registration' ),
				'i18n_install_only'            => esc_html__( 'Activate Plugins', 'user-registration' ),
				'i18n_activating'              => esc_html__( 'Activating', 'user-registration' ),
				'i18n_install_activate'        => esc_html__( 'Install & Activate', 'user-registration' ),
				'i18n_installing'              => esc_html__( 'Installing', 'user-registration' ),
				'i18n_ok'                      => esc_html__( 'OK', 'user-registration' ),

				/**
				 * Filters the Upgrade URL
				 *
				 * @param string URL
				 */
				'upgrade_url'                  => apply_filters( 'user_registration_upgrade_url', ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => 'form-template', 'medium' => 'button' ) ) ),
				'upgrade_button'               => esc_html__( 'Upgrade Plan', 'user-registration' ),
				'upgrade_message'              => esc_html__( 'This template requires premium addons. Please upgrade to the Premium plan to unlock all these awesome Templates.', 'user-registration' ),
				'upgrade_title'                => esc_html__( 'is a Premium Template', 'user-registration' ),
				'i18n_form_ok'                 => esc_html__( 'Continue', 'user-registration' ),
				'i18n_form_placeholder'        => esc_html__( 'Untitled Form', 'user-registration' ),
				'i18n_form_title'              => esc_html__( 'Uplift your form experience to the next level.', 'user-registration' ),
				'download_failed'              => esc_html__( 'Download Failed. Please download and activate addon manually.', 'user-registration' ),
				'download_successful_title'    => esc_html__( 'Installation Successful.', 'user-registration' ),
				'download_successful_message'  => esc_html__( 'Addons have been Installed and Activated. You have to reload the page.', 'user-registration' ),
				'i18n_activation_success'      => esc_html__( 'Successfully activated. Reloading...', 'user-registration' ),
				//              'save_changes_text'            => esc_html__( 'Save Changes and Reload', 'user-registration' ),
				'reload_text' => esc_html__( 'Ok', 'user-registration' ),
			)
		);

		wp_localize_script( 'user-registration-form-settings', 'user_registration_form_settings_params', array( 'ur_default_country_value_option' => apply_filters( 'user_registration_default_country_option', esc_html__( 'None', 'user-registration' ) ) ) );

		wp_register_script( 'ur-form-templates', UR()->plugin_url() . '/assets/js/admin/form-templates' . $suffix . '.js', array( 'jquery' ), UR_VERSION, true );
		wp_register_script( 'ur-copy', UR()->plugin_url() . '/assets/js/admin/ur-copy' . $suffix . '.js', 'jquery', UR_VERSION, false );
		wp_register_script( 'ur-my-account', UR()->plugin_url() . '/assets/js/frontend/my-account' . $suffix . '.js', array( 'jquery' ), UR_VERSION, false );
		wp_localize_script(
			'ur-my-account',
			'ur_my_account_params',
			array(
				'upload_image'     => __( 'Upload Profile Picture', 'user-registration' ),
				'select_image'     => __( 'Select Image', 'user-registration' ),
				'current_user_can' => current_user_can( 'edit_others_posts' ),
			)
		);

		wp_enqueue_script( 'ur-notice', UR()->plugin_url() . '/assets/js/admin/ur-notice' . $suffix . '.js', array(), UR_VERSION, false );
		wp_localize_script(
			'ur-notice',
			'ur_notice_params',
			array(
				'ajax_url'               => admin_url( 'admin-ajax.php' ),
				'important_nonce'        => wp_create_nonce( 'important-nonce' ),
				'info_nonce'             => wp_create_nonce( 'info-nonce' ),
				'review_nonce'           => wp_create_nonce( 'review-nonce' ),
				'allow-usage_nonce'      => wp_create_nonce( 'allow-usage-nonce' ),
				'survey_nonce'           => wp_create_nonce( 'survey-nonce' ),
				'promotional_nonce'      => wp_create_nonce( 'promotional-nonce' ),
				'urm-admin-notice_nonce' => wp_create_nonce( 'urm-admin-notice-nonce' ),
			)
		);

		wp_localize_script(
			'ur-enhanced-select',
			'ur_enhanced_select_params',
			array(
				'i18n_no_matches'           => _x( 'No matches found', 'enhanced select', 'user-registration' ),
				'i18n_ajax_error'           => _x( 'Loading failed', 'enhanced select', 'user-registration' ),
				'i18n_input_too_short_1'    => _x( 'Please enter 1 or more characters', 'enhanced select', 'user-registration' ),
				'i18n_input_too_short_n'    => _x( 'Please enter %qty% or more characters', 'enhanced select', 'user-registration' ),
				'i18n_input_too_long_1'     => _x( 'Please delete 1 character', 'enhanced select', 'user-registration' ),
				'i18n_input_too_long_n'     => _x( 'Please delete %qty% characters', 'enhanced select', 'user-registration' ),
				'i18n_selection_too_long_1' => _x( 'You can only select 1 item', 'enhanced select', 'user-registration' ),
				'i18n_selection_too_long_n' => _x( 'You can only select %qty% items', 'enhanced select', 'user-registration' ),
				'i18n_load_more'            => _x( 'Loading more results&hellip;', 'enhanced select', 'user-registration' ),
				'i18n_searching'            => _x( 'Searching&hellip;', 'enhanced select', 'user-registration' ),
			)
		);

		if ( 'user-registration-membership_page_user-registration-modules' === $screen_id ) {
			wp_enqueue_style( 'user-registration-modules' );
			wp_enqueue_script( 'user-registration-modules-script' );
			wp_localize_script(
				'user-registration-modules-script',
				'user_registration_module_params',
				array(
					'ajax_url'                => admin_url( 'admin-ajax.php' ),
					'error_could_not_install' => __( 'Could not install.', 'user-registration' ),
				)
			);
		}

		if ( isset( $_GET['page'] ) && 'user-registration' === $_GET['page'] ) {
			wp_localize_script(
				'user-registration-admin',
				'user_registration_all_forms',
				array(
					'ajax_all_forms_nonce' => wp_create_nonce( 'all-forms-ajax-nonce' ),
					'ajax_url'             => admin_url( 'admin-ajax.php' ),
				)
			);
		}

		// UserRegistration admin pages.
		if ( in_array( $screen_id, ur_get_screen_ids(), true ) ) {
			wp_enqueue_script( 'user-registration-admin' );
			wp_enqueue_script( 'user-registration-form-builder' );
			wp_enqueue_script( 'user-registration-form-settings' );
			wp_enqueue_script( 'jquery-confirm' );
			wp_enqueue_script( 'jquery-ui-sortable' );
			wp_enqueue_script( 'jquery-ui-autocomplete' );
			wp_enqueue_script( 'jquery-ui-widget' );
			wp_enqueue_script( 'ur-copy' );

			$form_id             = isset( $_GET['edit-registration'] ) ? absint( $_GET['edit-registration'] ) : 0;//phpcs:ignore WordPress.Security.NonceVerification
			$ur_enabled_captchas = array();
			$ur_captchas         = ur_get_captcha_integrations();
			foreach ( $ur_captchas as $key => $value ) {
				if ( get_option( 'user_registration_captcha_setting_recaptcha_enable_' . $key, false ) ) {
					$ur_enabled_captchas[ $key ] = $value;
				}
			}
			$no_captcha_set        = ( count( $ur_enabled_captchas ) < 1 );
			$captcha_not_set_error = sprintf(
			/* translators: %s - Integration tab url */
				'%s <a href="%s" class="ur-captcha-error" rel="noreferrer noopener" target="_blank">here</a> to add the captcha keys and save your changes.',
				esc_html__( 'Captcha setup is incomplete. Click', 'user-registration' ),
				esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=registration_login&section=captcha' ) )
			);
			$params = array(
				'required_form_html'                       => self::get_form_required_html(),
				'ajax_url'                                 => admin_url( 'admin-ajax.php' ),
				'user_input_dropped'                       => wp_create_nonce( 'user_input_dropped_nonce' ),
				'ur_form_save'                             => wp_create_nonce( 'ur_form_save_nonce' ),
				'ur_new_row_added'                         => wp_create_nonce( 'ur_new_row_added_nonce' ),
				'number_of_grid'                           => UR_Config::$ur_form_grid,
				'active_grid'                              => UR_Config::$default_active_grid,
				'is_edit_form'                             => isset( $_GET['edit-registration'] ) ? true : false, //phpcs:ignore WordPress.Security.NonceVerification
				'is_form_builder'                          => ( isset( $_GET['page'] ) && 'add-new-registration' === $_GET['page'] ) ? true : false, //phpcs:ignore WordPress.Security.NonceVerification
				'post_id'                                  => $form_id,
				'ur_embed_page_list'                       => wp_create_nonce( 'ur_embed_page_list_nonce' ),
				'ur_embed_action'                          => wp_create_nonce( 'ur_embed_action_nonce' ),
				'admin_url'                                => admin_url( 'admin.php?page=add-new-registration&edit-registration=' ),
				'form_required_fields'                     => ur_get_required_fields(),
				'form_one_time_draggable_fields'           => ur_get_one_time_draggable_fields(),
				'form_payment_fields'                      => function_exists( 'user_registration_payment_fields' ) ? user_registration_payment_fields() : array(),
				'form_repeater_row_not_droppable_fields_lists' => function_exists( 'user_registration_repeater_row_not_droppable_fields_lists' ) ? user_registration_repeater_row_not_droppable_fields_lists() : array(),
				'form_repeater_row_empty'                  => esc_html__( 'Please add at least one field to Repeater Row', 'user-registration' ),
				/* translators: %field%: Field Label */
				'form_one_time_draggable_fields_locked_title' => esc_html__( '%field% field is Locked.', 'user-registration' ),
				/* translators: %field%: Field Label */
				'form_one_time_draggable_fields_locked_message' => esc_html__( '%field% field can be used only one time in the form.', 'user-registration' ),
				'form_membership_payment_fields_disabled_message' => esc_html__( 'Payment fields cannot be used alongside the membership field.', 'user-registration' ),
				'form_membership_field_disabled_message'   => esc_html__( 'Membership field cannot be used alongside the payment fields.', 'user-registration' ),
				'form_has_membership_available'             => function_exists( 'ur_has_membership_available' ) ? ur_has_membership_available() : false,
				/* translators: %field%: Field Text */
				'form_membership_payment_settings_disabled_title' => esc_html__( '%field% setting is disabled.', 'user-registration' ),
				'form_membership_payment_settings_disabled_message' => esc_html__( 'Payment setting is not available when membership field is present in the form.', 'user-registration' ),
				'i18n_admin'                               => self::get_i18n_admin_data(),
				'i18n_shortcut_key_title'                  => esc_html__( 'Keyboard Shortcut Keys', 'user-registration' ),
				'i18n_publish_form_button_text'            => esc_html__( 'Publish form', 'user-registration' ),
				'i18n_update_form_button_text'             => esc_html__( 'Update form', 'user-registration' ),
				'i18n_captcha_not_set_error'               => $captcha_not_set_error,
				'i18n_shortcut_keys'                       => array(
					'Ctrl+S' => esc_html__( 'Save Builder', 'user-registration' ),
					'Ctrl+W' => esc_html__( 'Close Builder', 'user-registration' ),
					'Ctrl+P' => esc_html__( 'Preview Form', 'user-registration' ),
					'Ctrl+U' => esc_html__( 'Go to Users', 'user-registration' ),
					'Ctrl+H' => esc_html__( 'Open Help', 'user-registration' ),
				),
				'add_new'                                  => esc_html__( 'Add New', 'user-registration' ),
				'max_upload_size_ini'                      => wp_max_upload_size() / 1024,
				'ur_preview'                               => add_query_arg(
					array(
						'ur_preview' => 'true',
						'form_id'    => $form_id,
					),
					home_url()
				),
				'ur_placeholder'                           => UR()->plugin_url() . '/assets/images/UR-placeholder.png',
				'ur_user_list_table'             => admin_url( 'users.php?ur_specific_form_user=' . $form_id . '&ur_user_filter_action=Filter' ), //phpcs:ignore;
				'user_registration_very_weak_password_info' => esc_html__( 'Minimum one letter', 'user-registration' ),
				'user_registration_weak_password_info'     => esc_html__( 'Minimum one uppercase letter and must be 4 characters and no repetitive words or common words', 'user-registration' ),
				'user_registration_medium_password_info'   => esc_html__( 'Minimum one uppercase letter, a number, must be 7 characters and no repetitive words or common words', 'user-registration' ),
				'user_registration_strong_password_info'   => esc_html__( 'Minimum one uppercase letter, a number, a special character, must be 9 characters and no repetitive words or common words', 'user-registration' ),
				'user_registration_custom_password_info'   => esc_html__( 'Set custom passwords by defining criteria such as length, uppercase and lowercase letters, digits, and special characters for enhanced security.', 'user-registration' ),
				'ajax_form_submit_error_title'             => esc_html__( 'Form could not be saved', 'user-registration' ),
				'ajax_form_submit_error'                   => esc_html__( 'Something went wrong while saving form through AJAX request.', 'user-registration' ),
				'ajax_form_submit_troubleshooting_link'    => esc_url_raw( 'https://docs.wpuserregistration.com/docs/how-to-handle-ajax-submission-error' ),
				'isPro'                                    => is_plugin_active( 'user-registration-pro/user-registration.php' ),
				'ur_upgrade_plan_link'                     => esc_url( ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => 'plugin', 'medium' => 'button' ) ) ),
				'ur_remove_password_field_link'            => esc_url( 'https://docs.wpuserregistration.com/docs/remove-password-field/' ),
				'ur_form_non_deletable_fields'             => ur_non_deletable_fields(),
				'ur_assets_url'                            => UR()->plugin_url() . '/assets/',
				'i18n_prompt_no_membership_group_selected' => __( 'Membership Field requires a membership group to be selected.', 'user-registration' ),
				'i18n_default_redirection_notice_for_membership' => esc_html__( 'If the form includes a membership field, users will be redirected to the membership thank you page after submission.', 'user-registration' ),
				'i18n_email_confirmation_disabled_notice'  => esc_html__( 'If email confirmation is not enabled in email settings, users will not receive a confirmation email when this login option is selected.', 'user-registration' ),
				'email_confirmation_disabled'              => ur_string_to_bool( get_option( 'user_registration_enable_email_confirmation', true ) ) ? 'no' : 'yes',
				'form_has_membership_field'                => check_membership_field_in_form( $form_id ),
				'paypal_settings'                          => array(
					'global' => array(
						'paypal_mode'   => get_option( 'user_registration_global_paypal_mode', 'test' ),
						'paypal_email'  => get_option( 'user_registration_global_paypal_email_address', get_option( 'admin_email' ) ),
						'cancel_url'    => get_option( 'user_registration_global_paypal_cancel_url', home_url() ),
						'return_url'    => get_option( 'user_registration_global_paypal_return_url', wp_login_url() ),
						'client_id'     => get_option( 'user_registration_global_paypal_client_id', '' ),
						'client_secret' => get_option( 'user_registration_global_paypal_client_secret', '' ),
					),
					'form'   => array(
						'paypal_mode'  => ur_get_single_post_meta( $form_id, 'user_registration_paypal_mode', 'test' ),
						'paypal_email' => ur_get_single_post_meta( $form_id, 'user_registration_paypal_email_address', get_option( 'admin_email' ) ),
						'cancel_url'   => ur_get_single_post_meta( $form_id, 'user_registration_paypal_cancel_url', home_url() ),
						'return_url'   => ur_get_single_post_meta( $form_id, 'user_registration_paypal_return_url', wp_login_url() ),
					),
				),
				'no_captcha_set'                           => $no_captcha_set,
			);

			wp_localize_script(
				'user-registration-admin',
				'user_registration_admin_locate',
				array(
					'ajax_locate_nonce' => wp_create_nonce( 'process-locate-ajax-nonce' ),
					'ajax_url'          => admin_url( 'admin-ajax.php' ),
					'form_found_error'  => esc_html__( 'Form not found in content', 'user-registration' ),
					'form_found'        => esc_html__( 'Form found in page:', 'user-registration' ),
				)
			);

			wp_localize_script(
				'user-registration-admin',
				'user_registration_admin_data',
				array(
					'ajax_url'                  => admin_url( 'admin-ajax.php' ),
					'ur_import_form_save'       => wp_create_nonce( 'ur_import_form_save_nonce' ),
					'no_file_selected'          => esc_html__( 'No file selected.', 'user-registration' ),
					'export_error_message'      => esc_html__( 'Please choose at least one form to export.', 'user-registration' ),
					'smart_tags_dropdown_title' => esc_html__( 'Smart Tags', 'user-registration' ),
					'smart_tags_dropdown_search_placeholder' => esc_html__( 'Search Tags...', 'user-registration' ),
				)
			);
			wp_localize_script( 'user-registration-form-builder', 'user_registration_form_builder_data', $params );

			wp_register_script( 'ur-components', UR()->plugin_url() . '/assets/js/ur-components/ur-components' . $suffix . '.js', array( 'jquery' ), UR_VERSION, true );
			wp_enqueue_script( 'ur-components' );
			wp_localize_script(
				'ur-components',
				'ur_components_script_params',
				array(
					'ajax_url'                  => admin_url( 'admin-ajax.php' ),
					'card_switch_enabled_text'  => __( 'Enabled', 'user-registration' ),
					'card_switch_disabled_text' => __( 'Disabled', 'user-registration' ),
				)
			);
			wp_localize_script(
				'user-registration-admin',
				'user_registration_locked_form_fields_notice_params',
				array(
					'ajax_url'                            => admin_url( 'admin-ajax.php' ),
					'user_registration_locked_form_fields_notice_nonce' => wp_create_nonce( 'locked_form_fields_notice_nonce' ),
					'lock_message'                        => __( 'is a premium field', 'user-registration' ),
					/* translators: %field%: Field Label %plan%: License Plan. */
					'unlock_message'                      => __( '%field% field is locked. Upgrade to <strong>%plan%</strong> to unlock this field.', 'user-registration' ),
					'license_activation_required_title'   => __( 'License Activation Required', 'user-registration' ),
					'license_activation_required_message' => __( 'Please activate your <strong>User Registration & Membership License</strong> to use this field', 'user-registration' ),
					'activation_required_title'           => __( 'Addon Activation Required', 'user-registration' ),
					'activation_required_message'         => __( 'Please activate <strong>%plugin%</strong> addon to use this field.', 'user-registration' ),
					'installation_required_title'         => __( 'Addon Installation Required', 'user-registration' ),
					'installation_required_message'       => __( 'Please install <strong>%plugin%</strong> addon to use this field.', 'user-registration' ),

				)
			);
			wp_enqueue_script( 'user-registration-form-modal-js' );
			wp_enqueue_script( 'ur-enhanced-select' );
		}

		// Enqueue flatpickr on user profile screen.
		if ( 'user-edit' === $screen_id || 'profile' === $screen_id || 'user-registration-membership_page_add-new-registration' === $screen_id ) {
			wp_enqueue_script( 'flatpickr' );
			wp_enqueue_media();
			wp_enqueue_script( 'ur-my-account' );
		}

		if ( 'user-registration-membership_page_user-registration-dashboard' === $screen_id ) {
			wp_enqueue_script( 'ur-chartjs' );
		}
		// send test email.
		$current_tab = ! empty( $_REQUEST['tab'] ) ? sanitize_title( wp_unslash( $_REQUEST['tab'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification
		if ( 'user-registration-membership_page_user-registration-settings' === $screen_id && 'email' === $current_tab ) {
			wp_localize_script(
				'user-registration-admin',
				'user_registration_send_email',
				array(
					'ajax_url'         => admin_url( 'admin-ajax.php' ),
					'test_email_nonce' => wp_create_nonce( 'test_email_nonce' ),
				)
			);
		}

		$current_tab = ! empty( $_REQUEST['tab'] ) ? sanitize_title( wp_unslash( $_REQUEST['tab'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification
		if ( 'user-registration-membership_page_user-registration-settings' === $screen_id && 'email' === $current_tab ) {
			wp_localize_script(
				'user-registration-admin',
				'user_registration_email_setting_status',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'user_registration_email_setting_status_nonce' => wp_create_nonce( 'email_setting_status_nonce' ),
				)
			);
		}

		wp_register_script( 'ur-live-user-notice', UR()->plugin_url() . '/assets/js/admin/live-user-notice' . $suffix . '.js', array( 'jquery', 'heartbeat' ), UR_VERSION, false );
		wp_enqueue_script( 'ur-live-user-notice' );

		wp_register_script(
			'ur-enhanced-select-custom',
			UR()->plugin_url() . '/assets/js/admin/enhanced-select-custom' . $suffix . '.js',
			array(
				'jquery',
			),
			UR_VERSION,
			false
		);
	}

	/**
	 * Get Form Required HTML.
	 *
	 * @return string
	 */
	public static function get_form_required_html() {

		if ( isset( $_GET['edit-registration'] ) ) {//phpcs:ignore WordPress.Security.NonceVerification

			return '';
		}

		$form_html = '';

		$required_fields = ur_get_required_fields();

		foreach ( $required_fields as $field ) {

			$class_name    = ur_load_form_field_class( $field );
			$template_data = $class_name::get_instance()->get_admin_template(); // @codingStandardsIgnoreLine

			if ( null !== $class_name ) {

				$template = '<div class="ur-selected-item">';

				$template .= '<div class="ur-action-buttons"><span title="' . __( 'Clone', 'user-registration' ) . '" class="dashicons dashicons-admin-page ur-clone"></span><span title="' . __( 'Trash', 'user-registration' ) . '" class="dashicons dashicons-trash ur-trash"></span></div>';

				$template .= $template_data['template'];

				$template .= '</div>';

				$form_html .= $template;
			}
		}

		return $form_html;
	}

	/**
	 * Localize admin data.
	 *
	 * @return array
	 */
	public static function get_i18n_admin_data() {
		$max_upload_size_ini = wp_max_upload_size() / 1024;

		$i18n = array(
			'i18n_choice_delete'                          => esc_html__( 'Delete', 'user-registration' ),
			'i18n_choice_cancel'                          => esc_html__( 'Cancel', 'user-registration' ),
			'i18n_user_email'                             => _x( 'User Email', 'user-registration admin', 'user-registration' ),
			'i18n_user_password'                          => _x( 'User Password', 'user-registration admin', 'user-registration' ),
			'i18n_payment_field'                          => _x( 'Payment', 'user-registration admin', 'user-registration' ),
			'i18n_stripe_field'                           => _x( 'Stripe Gateway', 'user-registration admin', 'user-registration' ),
			'i18n_phone_field'                            => _x( 'Phone', 'user-registration admin', 'user-registration' ),
			'i18n_smart_phone_field'                      => _x( 'Selected default phone field must be in smart format.', 'user-registration admin', 'user-registration' ),
			'i18n_default_phone_field'                    => _x( 'Select Smart Phone Fields for SMS Verification', 'user-registration admin', 'user-registration' ),
			'i18n_anet_field'                             => _x( 'Authorize.net', 'user-registration admin', 'user-registration' ),
			'i18n_are_you_sure_want_to_delete_row'        => _x( 'Are you sure you want to delete this row?', 'user registration admin', 'user-registration' ),
			'i18n_are_you_sure_want_to_delete_field'      => _x( 'Are you sure you want to delete this field?', 'user registration admin', 'user-registration' ),
			'i18n_at_least_one_row_is_required_to_create_a_registration_form' => _x( 'At least one row is required to create a registration form.', 'user registration admin', 'user-registration' ),
			'i18n_cannot_delete_row'                      => _x( 'Cannot delete row', 'user registration admin', 'user-registration' ),
			'i18n_user_email_and_password_fields_are_required_to_create_a_registration_form' => _x( 'Email and Password fields are required to create a registration form.', 'user registration admin', 'user-registration' ),
			'i18n_user_required_field_already_there'      => _x( 'This field is one time draggable.', 'user registration admin', 'user-registration' ),
			'i18n_user_required_field_already_there_could_not_clone' => _x( 'Could not clone this field.', 'user registration admin', 'user-registration' ),
			/* translators: %field%: Field Label */
			'i18n_repeater_fields_not_droppable'          => _x( '%field% cannot be added to repeater row', 'user registration admin', 'user-registration' ),
			'i18n_form_successfully_saved'                => _x( 'Form successfully saved.', 'user registration admin', 'user-registration' ),
			'i18n_success'                                => _x( 'Success', 'user registration admin', 'user-registration' ),
			'i18n_error'                                  => _x( 'Error', 'user registration admin', 'user-registration' ),
			'i18n_msg_delete'                             => esc_html__( 'Confirm Deletion', 'user-registration' ),
			'i18n_embed_form_title'                       => esc_html__( 'Embed in Page', 'user-registration' ),
			'i18n_embed_description'                      => esc_html__( 'We can help embed your form with just a few clicks!', 'user-registration' ),
			'i18n_embed_to_existing_page'                 => esc_html__( 'Select Existing Page', 'user-registration' ),
			'i18n_embed_to_new_page'                      => esc_html__( 'Create New Page', 'user-registration' ),
			'i18n_embed_existing_page_description'        => esc_html__( 'Select the page to embed your form in.', 'user-registration' ),
			'i18n_embed_go_back_btn'                      => esc_html__( 'Go Back', 'user-registration' ),
			'i18n_embed_lets_go_btn'                      => esc_html__( "Let's Go!", 'user-registration' ),
			'i18n_embed_new_page_description'             => esc_html__( 'What would you like to call the new page?', 'user-registration' ),
			'i18n_at_least_one_field_need_to_select'      => _x( 'At least one field needs to be selected.', 'user registration admin', 'user-registration' ),
			'i18n_total_required_on_coupon'               => _x( 'Total field is required with coupon.', 'user registration admin', 'user-registration' ),
			'i18n_no_stripe_for_coupon'                   => _x( 'Recurring subscription with Stripe gateway is not currently available for coupon field.', 'user registration admin', 'user-registration' ),
			'i18n_min_custom_password_length_error'       => _x( 'Minimum Password Length value should at least be 6.', 'user registration admin', 'user-registration' ),
			'i18n_custom_password_negative_value_error'   => _x( 'Value in custom password cannot be less than 0.', 'user registration admin', 'user-registration' ),
			'i18n_empty_form_name'                        => _x( 'Empty form name.', 'user registration admin', 'user-registration' ),
			'i18n_previous_save_action_ongoing'           => _x( 'Previous save action on going.', 'user registration admin', 'user-registration' ),
			'i18n_duplicate_field_name'                   => _x( 'Duplicate field name.', 'user registration admin', 'user-registration' ),
			'i18n_empty_field_label'                      => _x( 'Empty field label.', 'user registration admin', 'user-registration' ),
			'i18n_invald_field_name'                      => _x( 'Invalid field name. Please do not use space, empty or special character, you can use underscore.', 'user registration admin', 'user-registration' ),
			'i18n_multiple_field_key'                     => _x( 'Multiple field key ', 'user registration admin', 'user-registration' ),
			'i18n_field_is_required'                      => _x( 'field is required.', 'user registration admin', 'user-registration' ),
			'i18n_drag_your_first_item_here'              => _x( 'Drag your first form item here.', 'user registration admin', 'user-registration' ),
			'i18n_select_countries'                       => _x( 'Please select at least one country.', 'user registration admin', 'user-registration' ),
			'i18n_input_size'                             => _x( 'input size must be greater than zero.', 'user registration admin', 'user-registration' ),
			'i18n_min_max_input'                          => _x( 'input of min value must be less than max value.', 'user registration admin', 'user-registration' ),
			'i18n_max_upload_size'                   => _x( 'input of max upload size must less than ' . $max_upload_size_ini . ' set in ini configuration', 'user registration admin', 'user-registration' ), // phpcs:ignore
			'i18n_pc_profile_completion_error'            => esc_html__( 'You cannot set the zero less than zero to the completion percentage.', 'user-registration' ),
			'i18n_pc_custom_percentage_filed_error'       => esc_html__( 'Sum of progress percentage for each field cannot be greater than the completion perecentage.', 'user-registration' ),
			'i18n_google_sheets_user_email_missing_error' => esc_html__( 'User Email field should me mapped.', 'user-registration' ),
			'i18n_google_sheets_sheet_empty_error'        => esc_html__( 'Look like your sheet is empty ! Please try again', 'user-registration' ),
			'i18n_urfr_qna_field_empty_error'             => esc_html__( 'Form Restriction: Empty Question or Answer field.', 'user-registration' ),
			'i18n_urfr_field_required_error'              => esc_html__( 'Form Restriction: Q&A restriction requires at least one question and answer.', 'user-registration' ),
			'i18n_delete_pass_available_in_pro'           => esc_html__( 'Subscribe to User Registration & Membership Pro to get the Autogenerated Password feature which lets you remove the password field.', 'user-registration' ),
			'i18n_auto_generate_password'                 => esc_html__( 'To remove the password field, enable the auto-generate password feature in form  settings.', 'user-registration' ),
			'i18n_this_field_is_required'                 => esc_html__( ' is required.', 'user-registration' ),
			'i18n_learn_more'                             => esc_html__( 'Learn More', 'user-registration' ),
			'i18n_upgrade_to_pro'                         => esc_html__( 'Upgrade plan', 'user-registration' ),
			'i18n_ok'                                     => esc_html__( 'OK', 'user-registration' ),
			'i18n_fullscreen_mode'                        => esc_html__( 'Fullscreen', 'user-registration' ),
			'i18n_exit_fullscreen_mode'                   => esc_html__( 'Exit Fullscreen', 'user-registration' ),
			'i18n_default_cannot_delete_message'          => esc_html__( 'WordPress requires the user to have an email address during registration.', 'user-registration' ),
			'pro_feature_title'                           => esc_html__( 'is a Pro Feature', 'user-registration' ),
			'upgrade_message'                             => esc_html__(
				'We apologize, but %title% is not available with the free version. To access this fantastic features, please consider upgrading to the %plan%.',
				'user-registration'
			),
			'upgrade_plan'                                => esc_html__( 'Upgrade Plan', 'user-registration' ),
			'upgrade_link'                                => esc_url( ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => 'integration-settings', 'medium' => 'popup' ) ) ),
			'user_registration_locked_form_fields_notice_nonce' => wp_create_nonce( 'locked_form_fields_notice_nonce' ),
			'license_activation_required_title'           => __( 'License Activation Required', 'user-registration' ),
			'license_activation_required_message'         => __( 'Please activate your <strong>User Registration & Membership License</strong> to use this integration', 'user-registration' ),
			'activation_required_title'                   => __( 'Addon Activation Required', 'user-registration' ),
			'activation_required_message'                 => __( 'Please activate <strong>%plugin%</strong> addon to use this integration.', 'user-registration' ),
			'installation_required_title'                 => __( 'Addon Installation Required', 'user-registration' ),
			'installation_required_message'               => __( 'Please install <strong>%plugin%</strong> addon to use this integration.', 'user-registration' ),
			'min_length_less_than_max_length'             => esc_html__( 'Minimum length count should be less than maximum length count for', 'user-registration' ),
			'invalid_max_length'                          => esc_html__( 'Invalid maximum length count for', 'user-registration' ),
			'invalid_min_length'                          => esc_html__( 'Invalid minimum length count for', 'user-registration' ),
			'i18n_min_max_mode'                           => _x( 'The max and min length limit mode for %field% must be same.', 'user registration admin', 'user-registration' ),
			'i18n_min_max_text_input'                     => _x( 'The max length limit for %field% must be greater than min length.', 'user registration admin', 'user-registration' ),
			'i18n_prompt_no_membership_group_selected'    => __( 'Please select a membership group for the selected membership field.', 'user-registration' ),
			'i18n_prompt_no_membership_available'         => __( 'Please create at least one active membership to use a membership field.', 'user-registration' ),
			'i18n_empty_membership_text'                  => __( 'No active membership\'s available', 'user-registration' ),
			'i18n_empty_membership_group_text'            => __( 'Please select a membership group.', 'user-registration' ),
			'i18n_prompt_payment_field_present'           => __( 'Membership Field does not require any additional payment fields. Please remove any/all payment\'s field to continue.', 'user-registration' ),
		);

		return $i18n;
	}
}

new UR_Admin_Assets();
