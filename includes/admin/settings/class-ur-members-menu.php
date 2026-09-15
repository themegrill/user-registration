<?php

/**
 * UserRegistration Members Menu class.
 *
 * @package  UserRegistration/Admin
 * @author   WPEverest
 *
 * @since 4.1
 */

use WPEverest\URMembership\Admin\Members\MembersListTable;
use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository;
use WPEverest\URMembership\Admin\Repositories\MembersOrderRepository;
use WPEverest\URMembership\Admin\Services\MembershipService;
use WPEverest\URRepeaterFields\Frontend\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'User_Registration_Members_Menu' ) ) {
	/**
	 * User_Registration_Members_Menu class.
	 */
	class User_Registration_Members_Menu {

		/**
		 * Errors attribute.
		 *
		 * @var [array]
		 */
		private $errors;

		/**
		 * Current page.
		 *
		 * @var string
		 */
		protected $page = null;

		/**
		 * Admin notice data.
		 *
		 * @var array
		 */
		private $notice_data = array();

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->page = 'user-registration-users';
			add_action( 'in_admin_header', array( $this, 'hide_unrelated_notices' ) );
			add_action( 'admin_init', array( $this, 'include_files' ) );
			// add_action( 'admin_menu', array( $this, 'add_members_menu_tab' ), 60 );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_filter(
				'manage_user-registration-membership_page_user-registration-users_columns',
				array(
					$this,
					'get_column_headers',
				)
			);
			add_filter(
				'bulk_actions-user-registration-membership_page_user-registration-users',
				array(
					$this,
					'manage_bulk_action_items',
				)
			);
			add_action( 'admin_init', array( $this, 'handle_actions' ) );
			add_action( 'admin_notices', array( $this, 'user_registration_handle_redirect_notices' ) );
		}

		public function include_files() {
			$is_user_registration_page = isset( $_REQUEST['page'] ) && 'user-registration-users' === $_REQUEST['page'] && ! empty( $_REQUEST['user_id'] );
			if ( ! $is_user_registration_page ) {
				return false;
			}
			if ( is_plugin_active( 'user-registration-file-upload/user-registration-file-upload.php' ) ) {
				include_once URFU_ABSPATH . 'includes/class-urfu-frontend.php';
			}
			if ( is_plugin_active( 'user-registration-advanced-fields/user-registration-advanced-fields.php' ) ) {
				include_once URAF_ABSPATH . 'includes/class-uraf-frontend.php';
			}
			if ( is_plugin_active( 'user-registration-repeater-fields/user-registration-repeater-fields.php' ) ) {
				new Frontend(); // included for support regarding repeater fields
			}
		}

		/**
		 * Remove Notices.
		 */
		public static function hide_unrelated_notices() {
			global $wp_filter;

			// Return on other than access rule creator page.
			if ( empty( $_REQUEST['page'] ) || 'user-registration-users' !== $_REQUEST['page'] ) {
				return;
			}

			foreach ( array( 'user_admin_notices', 'admin_notices', 'all_admin_notices' ) as $wp_notice ) {
				if ( ! empty( $wp_filter[ $wp_notice ]->callbacks ) && is_array( $wp_filter[ $wp_notice ]->callbacks ) ) {
					foreach ( $wp_filter[ $wp_notice ]->callbacks as $priority => $hooks ) {
						foreach ( $hooks as $name => $arr ) {
							// Remove all notices except user registration plugins notices.
							if ( ! strstr( $name, 'user_registration_' ) ) {
								unset( $wp_filter[ $wp_notice ]->callbacks[ $priority ][ $name ] );
							}
						}
					}
				}
			}
		}

		/**
		 * Enqueue styles
		 *
		 * @since 1.0.0
		 */
		public function enqueue_styles() {
			if ( empty( $_GET['page'] ) || 'user-registration-users' !== $_GET['page'] ) {
				return;
			}
			wp_register_style( 'ur-snackbar', UR()->plugin_url() . '/assets/css/ur-snackbar/ur-snackbar.css', array(), UR_VERSION );
			wp_register_style( 'ur-core-builder-style', UR()->plugin_url() . '/assets/css/admin.css', array(), UR_VERSION );
			wp_register_style( 'ur-membership-admin-style', UR()->plugin_url() . '/assets/css/modules/membership/user-registration-membership-admin.css', array(), UR_VERSION );
			wp_enqueue_style( 'ur-membership-admin-style' );
			wp_enqueue_style( 'user-registration-pro-admin-style' );
			wp_enqueue_style( 'sweetalert2' );
			wp_enqueue_style( 'ur-core-builder-style' );
			wp_enqueue_style( 'ur-snackbar' );
			wp_enqueue_style( 'select2', UR()->plugin_url() . '/assets/css/select2/select2.css', array(), UR_VERSION );
		}

		/**
		 * Enqueue scripts
		 *
		 * @since 1.0.0
		 */
		public function enqueue_scripts() {
			if ( empty( $_GET['page'] ) || 'user-registration-users' !== $_GET['page'] ) {
				return;
			}
			$suffix = defined( 'SCRIPT_DEBUG' ) ? '' : '.min';
			wp_register_script( 'ur-snackbar', UR()->plugin_url() . '/assets/js/ur-snackbar/ur-snackbar' . $suffix . '.js', array(), UR_VERSION, true );
			wp_register_script(
				'user-registration-members',
				UR()->plugin_url() . '/assets/js/modules/membership/admin/user-registration-members-admin' . $suffix . '.js',
				array(
					'jquery',
					'ur-enhanced-select',
					'user-registration-admin',
				),
				UR_VERSION,
				true
			);
			wp_enqueue_script( 'ur-snackbar' );
			wp_enqueue_script( 'user-registration-members' );
			wp_enqueue_script( 'sweetalert2' );
			wp_register_script( 'selectWoo', UR()->plugin_url() . '/assets/js/selectWoo/selectWoo.full' . $suffix . '.js', array( 'jquery' ), UR_VERSION, false );
			wp_enqueue_script( 'selectWoo' );
			$this->localize_scripts();
		}

		/**
		 * Localizes the scripts for the user registration membership plugin.
		 *
		 * Localizes scripts for user registration membership plugin.
		 *
		 * @return void
		 */
		public function localize_scripts() {
			$member_id = ! empty( $_GET['member_id'] ) ? wp_unslash( $_GET['member_id'] ) : null;
			if ( $member_id ) {
				$rule_as_wp_post = get_post( $member_id, ARRAY_A );
			}

			wp_localize_script(
				'user-registration-members',
				'ur_members_localized_data',
				array(
					'_nonce'                   => wp_create_nonce( 'ur_members' ),
					'edit_members_nonce'       => wp_create_nonce( 'ur_edit_members' ),
					'member_id'                => $member_id,
					'ajax_url'                 => admin_url( 'admin-ajax.php' ),
					'wp_roles'                 => ur_membership_get_all_roles(),
					'labels'                   => $this->get_i18_labels(),
					'members_page_url'         => admin_url( 'admin.php?page=user-registration-users' ),
					'delete_icon'              => plugins_url( 'assets/images/users/delete-user-red.svg', UR_PLUGIN_FILE ),
					'ur_membership_edit_nonce' => wp_create_nonce( 'ur_membership_edit_nonce' ),
				)
			);
		}

		/**
		 * Add admin scripts and styles.
		 *
		 * @return void
		 * @since 4.1
		 */
		public function admin_scripts() {
			$screen    = get_current_screen();
			$screen_id = $screen ? $screen->id : '';
			$suffix    = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			if ( isset( $_GET['page'] ) && 'user-registration-users' === $_GET['page'] && in_array( $screen_id, ur_get_screen_ids(), true ) ) {
				wp_enqueue_style( 'user-registration-pro-admin-style' );
				wp_enqueue_script(
					'user-registration-pro-users',
					plugins_url( '/assets/js/admin/user-registration-users-script.js', UR_PLUGIN_FILE ),
					array( 'jquery', 'sweetalert2' ),
					UR_VERSION,
					true
				);
				wp_enqueue_style( 'sweetalert2' );
				wp_enqueue_style( 'user-registration-pro-frontend-style' );

				wp_enqueue_script(
					'ur-inputmask',
					self::get_asset_url( '/assets/js/inputmask/jquery.inputmask.bundle' . $suffix . '.js' ),
					array( 'jquery' ),
					UR_VERSION,
					true
				);
				wp_enqueue_script(
					'ur-common',
					self::get_asset_url( 'assets/js/frontend/ur-common' . $suffix . '.js' ),
					array( 'jquery' ),
					UR_VERSION
				);

				wp_enqueue_script(
					'ur-jquery-validate',
					self::get_asset_url( '/assets/js/frontend/jquery.validate' . $suffix . '.js' ),
					array( 'jquery' ),
					UR_VERSION,
					true
				);
				wp_enqueue_script(
					'user-registration-edit-users',
					self::get_asset_url( '/assets/js/admin/user-registration-edit-users-script' . $suffix . '.js' ),
					array( 'jquery', 'ur-jquery-validate', 'ur-inputmask' ),
					UR_VERSION,
					true
				);

				wp_enqueue_script(
					'user-registration-backend-form-validator',
					self::get_asset_url( '/assets/js/admin/user-registration-backend-form-validator' . $suffix . '.js' ),
					array( 'jquery', 'user-registration-edit-users' ),
					UR_VERSION,
					true
				);

				wp_register_script( 'ur-snackbar', UR()->plugin_url() . '/assets/js/ur-snackbar/ur-snackbar' . $suffix . '.js', array(), UR_VERSION, true );

				wp_enqueue_script( 'ur-snackbar' );

				wp_register_style( 'ur-snackbar', UR()->plugin_url() . '/assets/css/ur-snackbar/ur-snackbar.css', array(), UR_VERSION );
				wp_enqueue_style( 'ur-snackbar' );
				wp_register_style( 'user-registration', UR()->plugin_url() . '/assets/css/user-registration.css', array( 'flatpickr' ), UR_VERSION );
				wp_enqueue_style( 'user-registration' );
			}

			wp_localize_script(
				'user-registration-pro-users',
				'urUsersl10n',
				array(
					'user_registration_form_data_save'     => wp_create_nonce( 'user_registration_form_data_save_nonce' ),
					'user_registration_profile_details_save' => wp_create_nonce( 'user_registration_profile_details_save_nonce' ),
					'user_registration_profile_picture_upload_nonce' => wp_create_nonce( 'user_registration_profile_picture_upload_nonce' ),
					'user_registration_profile_picture_remove_nonce' => wp_create_nonce( 'user_registration_profile_picture_remove_nonce' ),
					'login_option'                         => get_option( 'user_registration_general_setting_login_options' ),
					'recaptcha_type'                       => get_option( 'user_registration_captcha_setting_recaptcha_version', 'v2' ),
					'user_registration_profile_picture_uploading' => esc_html__( 'Uploading...', 'user-registration' ),
					'user_registration_profile_picture_removing' => esc_html__( 'Removing...', 'user-registration' ),
					'ajax_submission_on_edit_profile'      => ur_option_checked( 'user_registration_ajax_form_submission_on_edit_profile', false ),
					'ursL10n'                              => array(
						'user_successfully_saved'     => get_option( 'user_registration_successful_form_submission_message_manual_registation', esc_html__( 'User successfully registered.', 'user-registration' ) ),
						'user_under_approval'         => get_option( 'user_registration_successful_form_submission_message_admin_approval', esc_html__( 'User registered. Wait until admin approves your registration.', 'user-registration' ) ),
						'user_email_pending'          => get_option( 'user_registration_successful_form_submission_message_email_confirmation', esc_html__( 'User registered. Verify your email by clicking on the link sent to your email.', 'user-registration' ) ),
						'captcha_error'               => get_option( 'user_registration_form_submission_error_message_recaptcha', esc_html__( 'Captcha code error, please try again.', 'user-registration' ) ),
						'hide_password_title'         => esc_html__( 'Hide Password', 'user-registration' ),
						'show_password_title'         => esc_html__( 'Show Password', 'user-registration' ),
						'i18n_total_field_value_zero' => esc_html__( 'Total field value should be greater than zero.', 'user-registration' ),
						'i18n_discount_total_zero'    => esc_html__( 'Discounted amount cannot be less than or equals to Zero. Please adjust your coupon code.', 'user-registration' ),
						'password_strength_error'     => esc_html__( 'Password strength is not strong enough', 'user-registration' ),
					),
					'ajax_form_submit_error'               => esc_html__( 'Something went wrong while submitting form through AJAX request. Please contact site administrator.', 'user-registration' ),
					'ajax_url'                             => admin_url( 'admin-ajax.php' ),
					'change_column_nonce'                  => wp_create_nonce( 'ur-users-column-change' ),
					'user_registration_edit_user_nonce'    => wp_create_nonce( 'user_registration_profile_details_save_nonce' ),
					'message_required_fields'              => get_option( 'user_registration_form_submission_error_message_required_fields', esc_html__( 'This field is required.', 'user-registration' ) ),
					'message_email_fields'                 => get_option( 'user_registration_form_submission_error_message_email', esc_html__( 'Please enter a valid email address.', 'user-registration' ) ),
					'message_url_fields'                   => get_option( 'user_registration_form_submission_error_message_website_URL', esc_html__( 'Please enter a valid URL.', 'user-registration' ) ),
					'message_number_fields'                => get_option( 'user_registration_form_submission_error_message_number', esc_html__( 'Please enter a valid number.', 'user-registration' ) ),
					'message_confirm_password_fields'      => get_option( 'user_registration_form_submission_error_message_confirm_password', esc_html__( 'Password and confirm password not matched.', 'user-registration' ) ),
					'message_min_words_fields'             => get_option( 'user_registration_form_submission_error_message_min_words', esc_html__( 'Please enter at least %qty% words.', 'user-registration' ) ),
					'message_validate_phone_number'        => get_option( 'user_registration_form_submission_error_message_phone_number', esc_html__( 'Please enter a valid phone number.', 'user-registration' ) ),
					'message_username_character_fields'    => get_option( 'user_registration_form_submission_error_message_disallow_username_character', esc_html__( 'Please enter a valid username.', 'user-registration' ) ),
					'message_confirm_email_fields'         => get_option( 'user_registration_form_submission_error_message_confirm_email', esc_html__( 'Email and confirm email not matched.', 'user-registration' ) ),
					'message_confirm_number_field_max'     => esc_html__( 'Please enter a value less than or equal to %qty%.', 'user-registration' ),
					'message_confirm_number_field_min'     => esc_html__( 'Please enter a value greater than or equal to %qty%.', 'user-registration' ),
					'message_confirm_number_field_step'    => esc_html__( 'Please enter a multiple of %qty%.', 'user-registration' ),
					'form_required_fields'                 => ur_get_required_fields(),
					'edit_user_set_new_password'           => esc_html__( 'Set New Password', 'user-registration' ),
					'is_payment_compatible'                => true,
					'delete_prompt'                        => array(
						'icon'                   => plugins_url( 'assets/images/users/delete-user-red.svg', UR_PLUGIN_FILE ),
						'warning_message'        => __( 'All the member data and files will be permanently deleted.', 'user-registration' ),
						'title'                  => __( 'Delete Member', 'user-registration' ),
						'bulk_title'             => __( 'Delete Members', 'user-registration' ),
						'confirm_message_single' => __( 'Are you sure you want to delete this member permanently?', 'user-registration' ),
						'confirm_message_bulk'   => __( 'Are you sure you want to delete these members permanently?', 'user-registration' ),
						'delete_label'           => __( 'Delete', 'user-registration' ),
						'cancel_label'           => __( 'Cancel', 'user-registration' ),
					),
					'user_registration_update_state_field' => wp_create_nonce( 'user_registration_update_state_field_nonce' ),
				)
			);
			$this->localize_admin_script_data();
		}

		public function localize_admin_script_data() {
			wp_localize_script(
				'user-registration-pro-users',
				'user_registration_pro_admin_script_data',
				array(
					'ajax_url'                           => admin_url( 'admin-ajax.php' ),
					'ur_placeholder'                     => UR()->plugin_url() . '/assets/images/UR-placeholder.png',
					'disable_user_title'                 => __( 'Disable User', 'user-registration' ),
					'cancel'                             => __( 'Cancel', 'user-registration' ),
					'disable'                            => __( 'Disable', 'user-registration' ),
					'disable_user_placeholder'           => __( 'Enter Value', 'user-registration' ),
					'disable_user_success_message_title' => __( 'User Disabled Successfully', 'user-registration' ),
					'disable_user_success_message'       => __( 'The user has been disabled successfully. They will not be able to log in during the specified time frame.', 'user-registration' ),
					'disable_user_error_message_title'   => __( 'User cannot be disabled.', 'user-registration' ),
					'disable_user_error_message'         => __( 'There was an error disabling the user.', 'user-registration' ),
					'disable_user_popup_content'         => __( 'Please specify the timeframe to disable this user', 'user-registration' ),
					'after_disable_redirect_url'         => admin_url( 'admin.php?page=user-registration-users' ),
				)
			);
		}

		/**
		 * Get i18 labels.
		 *
		 * @return array
		 */
		public function get_i18_labels() {
			return array(
				'network_error'                        => esc_html__( 'Network error', 'user-registration' ),
				'i18n_error'                           => __( 'Error', 'user-registration' ),
				'i18n_field_is_required'               => _x( 'field is required.', 'user registration membership', 'user-registration' ),
				'i18n_field_email_field_validation'    => _x( 'Please enter a valid email address.', 'user registration membership', 'user-registration' ),
				'i18n_field_password_field_validation' => _x( 'Password does not match with confirm password.', 'user registration membership', 'user-registration' ),
				'i18n_field_subscription_start_date_validation' => _x( 'Start date must be greater than or equal to today.', 'user registration membership', 'user-registration' ),
				'i18n_prompt_title'                    => __( 'Delete Members', 'user-registration' ),
				'i18n_prompt_bulk_subtitle'            => __( 'Are you sure you want to delete these members permanently?', 'user-registration' ),
				'i18n_prompt_single_subtitle'          => __( 'Are you sure you want to delete this member permanently?', 'user-registration' ),
				'i18n_prompt_delete'                   => __( 'Delete', 'user-registration' ),
				'i18n_prompt_cancel'                   => __( 'Cancel', 'user-registration' ),
				'i18n_prompt_no_membership_selected'   => __( 'Please select at least one member.', 'user-registration' ),
			);
		}

		/**
		 * Bulk actions and single user action handler.
		 *
		 * @return void
		 * @since 4.1
		 */
		public function handle_actions() {
			global $wpdb;

			if ( ! ( ( isset( $_GET['page'] ) && 'user-registration-users' === $_GET['page'] ) ) ) {
				return;
			}

			if ( isset( $_REQUEST['action'] ) ) {

				if ( empty( $_REQUEST['users'] ) && empty( $_REQUEST['user_id'] ) ) {
					return;
				}

				$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );

				// edit/view only render the member for display (no state change), so they are not
				// nonce-gated — lets the payment-success admin email "Click Here" deep link work.
				// State-changing actions below stay nonce-protected.
				if ( ! in_array( $action, array( 'edit', 'view' ), true ) ) {
					check_admin_referer( 'bulk-users' );
				}

				if ( current_user_can( 'edit_users' ) ) {

					$userids = array();

					if ( ! empty( $_REQUEST['users'] ) ) {
						$userids = array_map( 'intval', (array) $_REQUEST['users'] );
					} elseif ( ! empty( $_REQUEST['user_id'] ) ) {
						$userids = array( (int) $_REQUEST['user_id'] );
					}

					switch ( $action ) {
						case 'edit':
							if ( ! current_user_can( 'edit_users' ) ) {
								$this->errors[] = new WP_Error( 'edit_users', __( 'Sorry, you are not allowed to edit users.', 'user-registration' ) );
								break;
							}
							break;
						case 'delete':
							if ( ! current_user_can( 'delete_users' ) ) {
								$this->errors[] = new WP_Error( 'edit_users', __( 'Sorry, you are not allowed to delete users.', 'user-registration' ) );
								break;
							}

							$userids = array_diff( $userids, array( get_current_user_id() ) );

							/**
							 * Check whether the user to be deleted has additional content in the site.
							 *
							 * @since 4.1
							 */
							$users_have_content = (bool) apply_filters( 'user_registration_users_have_additional_content', false, $userids );

							if ( $userids && ! $users_have_content ) {
								if ( $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_author IN( " . implode( ',', $userids ) . ' ) LIMIT 1' ) ) {
									$users_have_content = true;
								} elseif ( $wpdb->get_var( "SELECT link_id FROM {$wpdb->links} WHERE link_owner IN( " . implode( ',', $userids ) . ' ) LIMIT 1' ) ) {
									$users_have_content = true;
								}
							}

							if ( $users_have_content ) {
								$redirect_url = add_query_arg(
									array(
										'_wpnonce' => wp_create_nonce( 'bulk-users' ),
										'users'    => $userids,
										'action'   => 'delete',
										'action2'  => 'delete',
									),
									admin_url( 'users.php?s' )
								);

								wp_safe_redirect( esc_url_raw( $redirect_url ) );
								exit;
							}

							$delete_count = 0;

							foreach ( $userids as $id ) {
								if ( ! current_user_can( 'delete_user', $id ) ) {
									$user = get_userdata( $id );

									$this->errors[] = new WP_Error(
										'edit_users',
										sprintf(
										/* translators: %s: Username */
											esc_html__( 'Sorry, you are not allowed to delete the user %s.', 'user-registration' ),
											esc_html( $user->user_login )
										)
									);
									continue;
								}

								// remove user profile picture and profile picture related information.
								do_action( 'ur_remove_profile_pictures_and_metadata', $id );
								wp_delete_user( $id );

								++$delete_count;
							}

							if ( $delete_count ) {

								if ( isset( $_GET['view_user'] ) ) {
									$redirect = admin_url( 'admin.php?page=user-registration-users' );
									$redirect = add_query_arg( 'delete_count', 1, $redirect );

									wp_safe_redirect( $redirect );
									exit;
								} else {
									$redirect_url = add_query_arg(
										array(
											'delete_count' => $delete_count,
											'_wpnonce'     => wp_create_nonce( 'count-nonce' ),
											'count_type'   => 'delete',
										),
										admin_url( 'admin.php?page=user-registration-users' )
									);

									wp_safe_redirect( esc_url_raw( $redirect_url ) );
									exit;
								}
							}

							break;

						case 'resetpassword':
							$reset_count = 0;

							foreach ( $userids as $id ) {
								if ( ! current_user_can( 'edit_user', $id ) ) {
									$this->errors[] = new WP_Error( 'edit_users', __( 'Sorry, you are not allowed to edit this user.', 'user-registration' ) );
								}

								// Send the password reset link.
								$user = get_userdata( $id );
								if ( retrieve_password( $user->user_login ) ) {
									++$reset_count;
								}
							}

							if ( $reset_count ) {
								$redirect_url = add_query_arg(
									array(
										'reset_count' => $reset_count,
										'_wpnonce'    => wp_create_nonce( 'count-nonce' ),
										'count_type'  => 'reset',
									),
									admin_url( 'admin.php?page=user-registration-users' )
								);

								wp_safe_redirect( esc_url_raw( $redirect_url ) );
								exit;
							}

							break;

						case 'update_role':
							if ( ! current_user_can( 'promote_users' ) ) {
								$this->errors[] = new WP_Error( 'edit_users', __( 'Sorry, you are not allowed to update user roles.', 'user-registration' ) );
								break;
							}

							$editable_roles = get_editable_roles();
							$role           = isset( $_REQUEST['new_role'] ) ? sanitize_key( $_REQUEST['new_role'] ) : '';

							if ( ! $role || empty( $editable_roles[ $role ] ) ) {
								$this->errors[] = new WP_Error( 'edit_users', __( 'Sorry, you are not allowed to give users that role.', 'user-registration' ) );
								break;
							}

							$role_change_count = 0;

							foreach ( $userids as $id ) {
								$id = (int) $id;

								$user = get_userdata( $id );

								if ( ! current_user_can( 'promote_user', $id ) ) {
									$this->errors[] = new WP_Error(
										'edit_users',
										sprintf(
										/* translators: %s: Username */
											esc_html__( 'Sorry, you are not allowed to change role for user %s.', 'user-registration' ),
											esc_html( $user->user_login )
										)
									);
								}

								// If the user doesn't already belong to the blog, bail.
								if ( is_multisite() && ! is_user_member_of_blog( $id ) ) {
									wp_die(
										'<h1>' . __( 'Something went wrong.', 'user-registration' ) . '</h1>' .
											'<p>' . __( 'One of the selected users is not a member of this site.', 'user-registration' ) . '</p>',
										403
									);
								}

								// User cannot self-update their own role.
								if ( $id === get_current_user_id() ) {
									$this->errors[] = new WP_Error( 'edit_users', 'Sorry, you are not allowed to change your own role.' );
									continue;
								}

								$user->set_role( $role );
								++$role_change_count;
							}

							if ( $role_change_count ) {
								$redirect_url = add_query_arg(
									array(
										'role_change_count' => $role_change_count,
										'_wpnonce'   => wp_create_nonce( 'count-nonce' ),
										'count_type' => 'role_change',
									),
									admin_url( 'admin.php?page=user-registration-users' )
								);

								wp_safe_redirect( esc_url_raw( $redirect_url ) );
								exit;
							}

							break;

						case 'approve':
							if ( ! current_user_can( 'promote_users' ) ) {
								$this->errors[] = new WP_Error( 'edit_users', __( 'Sorry, you are not allowed to approve the users.', 'user-registration' ) );
								break;
							}

							$approval_count = 0;

							foreach ( $userids as $user_id ) {
								try {
									$user_manager = new UR_Admin_User_Manager( $user_id );
									$form_id      = ur_get_form_id_by_userid( $user_id );
									$login_option = ur_get_single_post_meta( $form_id, 'user_registration_form_setting_login_options', get_option( 'user_registration_general_setting_login_options', 'default' ) );

									$user_manager->approve();

									if ( 'email_confirmation' === $login_option || 'admin_approval_after_email_confirmation' === $login_option ) {
										update_user_meta( $user_id, 'ur_confirm_email', '1' );
										delete_user_meta( $user_id, 'ur_confirm_email_token' );
										if ( 'admin_approval_after_email_confirmation' === $login_option ) {
											update_user_meta( $user_id, 'ur_admin_approval_after_email_confirmation', 'true' );
										}
									}

									++$approval_count;
								} catch ( Exception $e ) {
									$this->errors[] = new WP_Error( 'edit_users', $e->getMessage() );
								}
							}

							if ( $approval_count ) {
								$redirect_url = add_query_arg(
									array(
										'approval_count' => $approval_count,
										'_wpnonce'       => wp_create_nonce( 'count-nonce' ),
										'count_type'     => 'approval',
									),
									admin_url( 'admin.php?page=user-registration-users' )
								);

								wp_safe_redirect( esc_url_raw( $redirect_url ) );
								exit;
							}

							break;

						case 'disable_user':
						case 'enable_user':
							if ( ! current_user_can( 'edit_user' ) ) {
								$this->errors[] = new WP_Error( 'edit_users', __( 'Sorry, you are not allowed to perform this action.', 'user-registration' ) );
								break;
							}
							$enable_disable_count = 0;
							$count_type           = 'enable_user' === $action ? 'enable_user' : 'disable_user';

							foreach ( $userids as $user_id ) {
								$current_status = get_user_meta( $user_id, 'ur_disable_users', true );

								if ( 'enable_user' === $action ) {
									if ( empty( $current_status ) ) {
										continue;
									}

									delete_user_meta( $user_id, 'ur_disable_users' );
									delete_user_meta( $user_id, 'ur_auto_enable_time' );
									++$enable_disable_count;

								} elseif ( 'disable_user' === $action ) {
									if ( '1' === (string) $current_status ) {
										continue;
									}

									update_user_meta( $user_id, 'ur_disable_users', true );
									++$enable_disable_count;
								}
							}
							if ( $enable_disable_count ) {
								$redirect_url = add_query_arg(
									array(
										'enable_disable_count' => $enable_disable_count,
										'_wpnonce'   => wp_create_nonce( 'count-nonce' ),
										'count_type' => $count_type,
									),
									admin_url( 'admin.php?page=user-registration-users' )
								);

								wp_safe_redirect( esc_url_raw( $redirect_url ) );
								exit;
							} else {
								$redirect_url = add_query_arg(
									array(
										'enable_disable_count' => 'no_change',
										'_wpnonce'   => wp_create_nonce( 'count-nonce' ),
										'count_type' => $count_type,
									),
									admin_url( 'admin.php?page=user-registration-users' )
								);

								wp_safe_redirect( esc_url_raw( $redirect_url ) );
								exit;
							}
							break;

						case 'resend_email':
							if ( ! current_user_can( 'promote_users' ) ) {
								$this->errors[] = new WP_Error( 'edit_users', __( 'Sorry, you are not allowed to edit the users.', 'user-registration' ) );
								break;
							}

							$await_count = 0;

							foreach ( $userids as $user_id ) {
								try {
									$user_manager = new UR_Admin_User_Manager( $user_id );
									$status       = $user_manager->is_email_pending();
									if ( $status ) {
										ur_resend_verification_email( $user_id );
										++$await_count;
									}
								} catch ( Exception $e ) {
									$this->errors[] = new WP_Error( 'edit_users', $e->getMessage() );
								}
							}

							if ( $await_count ) {
								$redirect_url = add_query_arg(
									array(
										'await_count' => $await_count,
										'_wpnonce'    => wp_create_nonce( 'count-nonce' ),
										'count_type'  => 'await',
									),
									admin_url( 'admin.php?page=user-registration-users' )
								);

								wp_safe_redirect( esc_url_raw( $redirect_url ) );
								exit;
							}
							break;

						case 'deny':
							if ( ! current_user_can( 'promote_users' ) ) {
								$this->errors[] = new WP_Error( 'edit_users', __( 'Sorry, you are not allowed to deny the users.', 'user-registration' ) );
								break;
							}

							$denial_count = 0;

							foreach ( $userids as $user_id ) {
								try {
									$user_manager = new UR_Admin_User_Manager( $user_id );
									$form_id      = ur_get_form_id_by_userid( $user_id );
									$login_option = ur_get_single_post_meta( $form_id, 'user_registration_form_setting_login_options', get_option( 'user_registration_general_setting_login_options', 'default' ) );

									if ( current_user_can( 'manage_options' ) && $user_id === get_current_user_id() ) {
										$this->errors[] = new WP_Error( 'edit_users', __( 'Sorry, Admin cannot deny themselves.', 'user_registration' ) );
										continue;
									}
									$user_manager->deny();

									if ( 'email_confirmation' === $login_option || 'admin_approval_after_email_confirmation' === $login_option ) {
										update_user_meta( $user_id, 'ur_confirm_email', '0' );
										delete_user_meta( $user_id, 'ur_confirm_email_token' );
										if ( 'admin_approval_after_email_confirmation' === $login_option ) {
											update_user_meta( $user_id, 'ur_admin_approval_after_email_confirmation', 'denied' );
										}
									}

									++$denial_count;
								} catch ( Exception $e ) {
									$this->errors[] = new WP_Error( 'edit_users', $e->getMessage() );
								}
							}

							if ( $denial_count ) {
								$redirect_url = add_query_arg(
									array(
										'denial_count' => $denial_count,
										'_wpnonce'     => wp_create_nonce( 'count-nonce' ),
										'count_type'   => 'denial',
									),
									admin_url( 'admin.php?page=user-registration-users' )
								);

								wp_safe_redirect( esc_url_raw( $redirect_url ) );
								exit;
							}
							break;

						default:
							do_action( 'user_registration_users_do_bulk_' . $action, $userids );
							break;
					}
				} else {
					$this->errors[] = new WP_Error( 'edit_users', __( 'Sorry, you are not allowed to edit users.', 'user-registration' ) );
				}

				if ( ! empty( $this->errors ) ) {
					foreach ( $this->errors as $error ) {
						$this->notice_data = array(
							'type'    => 'error',
							'error'   => $error,
							'message' => $message,
						);
						add_action( 'admin_notices', array( $this, 'user_registration_show_admin_notice' ) );
					}
				}
			}
		}

		/**
		 * Display Notices for actions that require redirections.
		 *
		 * @return void
		 */
		public function user_registration_handle_redirect_notices() {
			$message = '';
			$nonce   = isset( $_REQUEST['_wpnonce'] ) ? wp_unslash( sanitize_key( $_REQUEST['_wpnonce'] ) ) : '';

			$flag = wp_verify_nonce( $nonce, 'count-nonce' );

			if ( ! isset( $_REQUEST['count_type'] ) ) {
				return;
			}

			if ( true != $flag || is_wp_error( $flag ) ) {
				$message = __( 'Nonce error, please reload.', 'user-registration' );
			} else {

				if ( isset( $_REQUEST['reset_count'] ) ) {
					$user_reset_count = absint( $_REQUEST['reset_count'] );
					$count_message    = 1 < $user_reset_count ? " to {$user_reset_count} users" : '';
					/* translators: Count message */
					$message = sprintf( __( 'Reset password email sent %s successfully.', 'user-registration' ), $count_message );
				}

				if ( isset( $_REQUEST['role_change_count'] ) ) {
					$user_role_change_count = absint( $_REQUEST['role_change_count'] );
					/* translators: Count message */
					$message = sprintf( __( 'Roles updated for %s users successfully.', 'user-registration' ), $user_role_change_count );
				}

				if ( isset( $_REQUEST['approval_count'] ) ) {
					$user_approval_count = absint( $_REQUEST['approval_count'] );
					$count_message       = 1 < $user_approval_count ? $user_approval_count . ' users' : 'User';
					/* translators: Count message */
					$message = sprintf( __( '%s approved successfully.', 'user-registration' ), $count_message );
				}

				if ( isset( $_REQUEST['await_count'] ) ) {
					$user_await_count = absint( $_REQUEST['await_count'] );
					$count_message    = 1 < $user_await_count ? $user_await_count . ' users' : 'User';
					/* translators: Count message */
					$message = sprintf( __( 'Verification email sent to %s successfully.', 'user-registration' ), $count_message );
				}

				if ( isset( $_REQUEST['denial_count'] ) ) {
					$user_denial_count = absint( $_REQUEST['denial_count'] );
					$count_message     = 1 < $user_denial_count ? $user_denial_count . ' users' : 'User';
					/* translators: Count message */
					$message = sprintf( __( '%s denied successfully.', 'user-registration' ), $count_message );
				}

				if ( isset( $_REQUEST['delete_count'] ) ) {
					$user_delete_count = absint( $_REQUEST['delete_count'] );
					$count_message     = 1 < $user_delete_count ? $user_delete_count . ' users' : 'User';
					/* translators: Count message */
					$message = sprintf( __( '%s deleted successfully.', 'user-registration' ), $count_message );
				}

				if ( isset( $_REQUEST['enable_disable_count'] ) ) {
					if ( 'no_change' === $_REQUEST['enable_disable_count'] ) {
						if ( 'enable_user' === $_REQUEST['count_type'] ) {
							$message = __( 'Selected Users are Already Enabled.', 'user-registration' );
						} else {
							$message = __( 'Selected Users are Already Disabled.', 'user-registration' );
						}
					} else {
						$enable_disable_count = absint( $_REQUEST['enable_disable_count'] );
						$count_message        = 1 < $enable_disable_count ? $enable_disable_count . ' users' : 'User';
						if ( 'enable_user' === $_REQUEST['count_type'] ) {
							/* translators: Count message */
							$message = sprintf( __( '%s enabled successfully.', 'user-registration' ), $count_message );
						} else {
							/* translators: Count message */
							$message = sprintf( __( '%s disabled successfully.', 'user-registration' ), $count_message );
						}
					}
				}
			}

			ob_start();
			?>
			<div class='updated notice ur-toaster ur-users-notice is-dismissible'>
				<p>
					<?php
					echo esc_html( $message );
					?>
				</p>
			</div>
			<?php
			$message_div = ob_get_clean();

			echo wp_kses_post( $message_div );
		}

		/**
		 * Add Members submenu to User Registration Menus.
		 *
		 * @return void
		 * @since 4.1
		 */
		public function add_members_menu_tab() {
			add_submenu_page(
				'user-registration',
				__( 'User Registration Members', 'user-registration' ),
				__( 'Members', 'user-registration' ),
				'manage_user_registration',
				'user-registration-users',
				array( $this, 'render_members_page' ),
			);
		}

		/**
		 * Returns the list of column headers for Users list table.
		 *
		 * @return array
		 * @since 4.1
		 */
		public function get_column_headers() {
			$membership_repository = new MembershipRepository();
			$membership_plans      = $membership_repository->get_all_membership();

			if ( ( get_option( 'urm_onboarding_membership_type', 'normal' ) === 'normal' || get_option( 'user_registration_onboarding_skipped', false ) ) && empty( $membership_plans ) ) {
				$column_headers = apply_filters(
					'user_registration_users_table_column_headers',
					array(
						'cb'              => '<input type="checkbox" />',
						'username'        => __( 'Username', 'user-registration' ),
						'email'           => __( 'Email', 'user-registration' ),
						'role'            => __( 'Role', 'user-registration' ),
						'user_status'     => __( 'User Status', 'user-registration' ),
						'user_registered' => __( 'Registered On', 'user-registration' ),
					)
				);
			} else {
				$headers = array(
					'cb'                  => '<input type="checkbox" />',
					'username'            => __( 'Username', 'user-registration' ),
					'email'               => __( 'Email', 'user-registration' ),
					'membership'          => __( 'Membership', 'user-registration' ),
					'subscription_status' => __( 'Subscription Status', 'user-registration' ),
				);

				if ( UR_PRO_ACTIVE && ur_check_module_activation( 'team' ) ) {
					$headers['team'] = __( 'Team', 'user-registration' );
				}
				$headers['user_registered'] = __( 'Registered On', 'user-registration' );
				$column_headers             = apply_filters(
					'user_registration_users_table_column_headers',
					$headers
				);
			}

			return $column_headers;
		}

		/**
		 * Renders the members create or add new page.
		 *
		 * @return void
		 */
		public function render_members_page() {
			$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
			switch ( $action ) {
				case 'add':
					$this->render_members_create_page();
					break;
				case 'view':
					$this->render_members_view_page();
					break;
				case 'edit':
					$this->render_members_view_page();
					break;
				default:
					$this->render_members_list_page();
					break;
			}
		}

		/**
		 * Render members list page.
		 *
		 * @return void
		 */
		public function render_members_list_page() {
			if ( ! current_user_can( 'list_users' ) ) {
				wp_die(
					'<h1>' . esc_html__( 'You need a higher level of permission.', 'user-registration' ) . '</h1>' .
					'<p>' . esc_html__( 'Sorry, you are not allowed to list users.', 'user-registration' ) . '</p>',
					403
				);
			}
			include_once UR_ABSPATH . 'includes/admin/settings/class-ur-members-list-table.php';
			include_once UR_ABSPATH . 'includes/admin/class-ur-admin-base-layout.php';

			$list_table = new User_Registration_Members_List_Table();
			echo user_registration_plugin_main_header();
			$base_data = array(
				'page'       => $this->page,
				'title'      => esc_html__( 'Members', 'user-registration' ),
				'search_id'  => 'user-registration-users-search-input',
				'form_id'    => 'user-registration-members-list-form',
				'class'      => 'user-registration-users-page',
				'form_class' => 'user-registration-base-list-table-action-form',
			);
			if ( ur_check_module_activation( 'membership' ) ) {
				$base_data['add_new_action'] = 'add';
			}
			UR_Base_Layout::render_layout( $list_table, $base_data );
		}

		/**
		 * Renders the members create page.
		 *
		 * @return void
		 */
		public function render_members_create_page() {
			$members_list_table = new MembersListTable();
			$roles              = $members_list_table->get_roles();
			$memberships        = $members_list_table->get_all_memberships();
			$membership_service = new MembershipService();
			$memberships        = $membership_service->list_active_memberships();
			include UR_MEMBERSHIP_DIR . '/includes/Admin/Views/member-create.php';
		}

		/**
		 * Renders the members edit page.
		 *
		 * @return void
		 */
		public function render_members_edit_page() {
			$member_id          = ! empty( $_GET['member_id'] ) ? absint( $_GET['member_id'] ) : '';
			$member             = get_user( $member_id );
			$members_list_table = new MembersListTable();
			$membership_service = new MembershipService();
			$roles              = $members_list_table->get_roles();
			$memberships        = $membership_service->list_active_memberships();

			if ( ! empty( $member_id ) ) {
				$subscription_repository = new MembersSubscriptionRepository();
				$membership_repository   = new MembershipRepository();

				$member_subscription = $subscription_repository->get_member_subscription( $member_id );
				$member_membership   = $membership_repository->get_single_membership_by_ID( $member_subscription['item_id'] );

				$member_membership_details['ID']           = $member_subscription['item_id'];
				$member_membership_details['post_title']   = $member_membership['post_title'];
				$member_membership_details['post_content'] = json_decode( $member_membership['post_content'], true );
				$member_membership_details['meta_value']   = json_decode( $member_membership['meta_value'], true );

				$membership_price_details = apply_filters( 'build_membership_list_frontend', array( (array) $member_membership_details ) )[0];
			}
			include UR_MEMBERSHIP_DIR . '/includes/Admin/Views/member-create.php';
		}

		/**
		 * Render user single page content.
		 *
		 * @return void
		 * @since 4.1
		 */
		public function render_members_view_page() {
			// Read-only member view: protect by capability, not a nonce, so the payment-success
			// admin email "Click Here" deep link works (a background email can't carry a valid
			// nonce). A valid nonce still passes.
			$has_valid_nonce = isset( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-users' );
			if ( ! $has_valid_nonce && ! current_user_can( 'list_users' ) && ! current_user_can( 'manage_user_registration' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'user-registration' ) );
			}

			$user_id = isset( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : 0;

			if ( empty( $user_id ) ) {
				return;
			}

			$user = get_userdata( $user_id );

			if ( ! $user ) {
				$redirect = admin_url( 'admin.php?page=user-registration-users' );
				wp_safe_redirect( $redirect );
				exit;
			}

			$user_extra_fields        = ur_get_user_extra_fields( $user_id );
			$user_data                = (array) $user->data;
			$user_data['first_name']  = get_user_meta( $user_id, 'first_name', true );
			$user_data['last_name']   = get_user_meta( $user_id, 'last_name', true );
			$user_data['description'] = get_user_meta( $user_id, 'description', true );
			$user_data['nickname']    = get_user_meta( $user_id, 'nickname', true );
			$user_data                = array_merge( $user_data, $user_extra_fields );

			$form_id               = ur_get_form_id_by_userid( $user_id );
			$form_field_data_array = user_registration_profile_details_form_fields( $form_id );
			$user_data_to_show     = user_registration_profile_details_form_field_datas( $form_id, $user_data, $form_field_data_array );
			$show_profile_picture  = get_option( 'user_registration_disable_profile_picture', true );
			$back_url              = admin_url( 'admin.php?page=user-registration-users' );
			?>
			<div class="ur-admin-page-topnav" id="ur-lists-page-topnav">
				<div class="ur-page-title__wrapper">
					<div class="ur-page-title__wrapper--left">
						<a class="ur-text-muted ur-border-right ur-d-flex ur-mr-2 ur-pl-2 ur-pr-2" href="<?php echo esc_attr( $back_url ); ?>">
							<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
						</a>
						<div class="ur-page-title__wrapper--left-menu">
							<div class="ur-page-title__wrapper--left-menu__items">
								<p><?php esc_html_e( 'User Details', 'user-registration' ); ?></p>
							</div>
						</div>
					</div>
				</div>
			</div>
			<span class="wp-header-end"></span>

			<div id="user-registration-pro-single-user-view">
				<div id="user-registration-user-sidebar">
					<?php $this->render_user_profile( $user_id ); ?>
					<?php $this->render_user_actions($user_id); //phpcs:ignore ?>
					<?php
					/**
					 * Add more sections to the sidebar of user view page.
					 *
					 * @param int $user_id User Id.
					 */
					do_action( 'user_registration_user_view_sidebar', $user_id );
					?>
				</div>
				<div class="user-registration-user-content">
					<?php
					if ( isset( $_GET['tab'] ) && 'user-actions' === $_GET['tab'] ) {
						?>
						<!-- <div id="user-registration-user-actions" class="user-registration-user-body">
							<?php
							// $this->render_user_settings_section( $user_id );
							?>
						</div> -->
						<?php
					} elseif ( isset( $_GET['action'] ) ) {

						if ( 'edit' === $_GET['action'] ) {
							$this->render_user_edit_form_fields( $user_id, true );
							$this->render_user_form_fields( $user_id, false );
						} else {
							$this->render_user_edit_form_fields( $user_id, false );
							$this->render_user_form_fields( $user_id, true );
						}
					}
					?>
					<?php $this->render_user_extra_details( $user_id, true ); ?>
					<?php $this->render_user_payment_details( $user_id, true ); ?>
					<?php do_action( 'user_registration_single_user_details_content', $user_id, $form_id ); ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Display user profile image and username.
		 *
		 * @param [int] $user_id User Id.
		 *
		 * @return void
		 */
		public function render_user_profile( $user_id ) {
			$user         = get_userdata( $user_id );
			$avatar       = get_avatar( $user_id, 900 );
			$first_name   = get_user_meta( $user_id, 'first_name', true );
			$last_name    = get_user_meta( $user_id, 'last_name', true );
			$display_name = '';

			if ( '' !== $first_name && '' !== $last_name ) {
				$display_name = $first_name . ' ' . $last_name;
			}

			if ( '' === $display_name ) {
				$display_name = $user->user_login;
			}

			$allowed_avatar_html = array(
				'img' => array(
					'src'      => true,
					'srcset'   => true,
					'alt'      => true,
					'width'    => true,
					'height'   => true,
					'class'    => true,
					'id'       => true,
					'loading'  => true,
					'decoding' => true,
				),
			);

			?>
			<div class="sidebar-box">
				<div class="user-profile">
					<div class="user-avatar">
						<?php echo wp_kses( $avatar, $allowed_avatar_html ); ?>
					</div>
					<div class="user-login">
						<p  class="user-display-name"><?php echo esc_html( $display_name ); ?> </p>
						<p  class="user-display-email"><?php echo esc_html( $user->user_email ); ?> </p>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Returns the html for the user actions sidebar.
		 *
		 * @param [int] $user_id User Id.
		 *
		 * @return string
		 * @since 4.1
		 */
		public function render_user_actions( $user_id ) {
			$actions = array();

			$user = get_userdata( $user_id );

			if ( current_user_can( 'edit_user', $user_id ) ) {
				// 1. Edit User

				$edit_link    = add_query_arg(
					array(
						'action'   => 'edit',
						'user_id'  => $user_id,
						'_wpnonce' => wp_create_nonce( 'bulk-users' ),
					),
					admin_url( 'admin.php?page=user-registration-users&view_user&action=edit' ),
				);
				$active_class = '';

				// phpcs:disable WordPress.Security.NonceVerification.Recommended
				$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

				if ( 'edit' === $action ) {
					$active_class = 'active';
				}
				// $actions['edit'] = sprintf(
				// '<a href="%s" rel="noreferrer noopener" class="%s" target="_self">%s <p>%s</p></a>',
				// esc_url( $edit_link ),
				// $active_class,
				// '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
				// <path fill="#000" fill-rule="evenodd" d="M19.207 3.207a1.121 1.121 0 0 1 1.586 1.586l-9.304 9.304-2.115.529.529-2.114 9.304-9.305ZM20 .88c-.828 0-1.622.329-2.207.914l-9.5 9.5a1 1 0 0 0-.263.465l-1 4a1 1 0 0 0 1.213 1.212l4-1a1 1 0 0 0 .464-.263l9.5-9.5A3.121 3.121 0 0 0 20 .88ZM4 3a3 3 0 0 0-3 3v14a3 3 0 0 0 3 3h14a3 3 0 0 0 3-3v-7a1 1 0 1 0-2 0v7a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h7a1 1 0 1 0 0-2H4Z" clip-rule="evenodd"/>
				// </svg>',
				// __( 'Edit User', 'user-registration' ),
				// );

				// 2. Approve/Deny User
				$user_manager = new UR_Admin_User_Manager( $user );
				$status       = $user_manager->get_user_status();

				if ( ! empty( $status ) ) {
					$user_status = esc_html( UR_Admin_User_Manager::get_status_label( $status['user_status'] ) );
				}

				$approve_link = add_query_arg(
					array(
						'action'   => 'approve',
						'user_id'  => $user_id,
						'_wpnonce' => wp_create_nonce( 'bulk-users' ),
					),
					admin_url( 'admin.php?page=user-registration-users&view_user' ),
				);

				$deny_link = add_query_arg(
					array(
						'action'   => 'deny',
						'user_id'  => $user_id,
						'_wpnonce' => wp_create_nonce( 'bulk-users' ),
					),
					admin_url( 'admin.php?page=user-registration-users&view_user' ),
				);

				if ( 'Pending' === $user_status || 'Denied' === $user_status ) {

					$actions['approve'] = sprintf(
						'<a href="%s">%s <p>%s</p></a>',
						esc_url( $approve_link ),
						'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
							<path fill="#000" fill-rule="evenodd" d="M8.5 4a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm-5 3a5 5 0 1 1 10 0 5 5 0 0 1-10 0Zm-2.036 8.464A5 5 0 0 1 5 14h7a5 5 0 0 1 5 5v2a1 1 0 1 1-2 0v-2a3 3 0 0 0-3-3H5a3 3 0 0 0-3 3v2a1 1 0 1 1-2 0v-2a5 5 0 0 1 1.464-3.536Zm22.243-5.757a1 1 0 0 0-1.414-1.414L19 11.586l-1.293-1.293a1 1 0 1 0-1.414 1.414l2 2a1 1 0 0 0 1.414 0l4-4Z" clip-rule="evenodd"/>
						</svg>',
						__( 'Approve User', 'user-registration' ),
					);
				}

				if ( 'Pending' === $user_status || 'Approved' === $user_status ) {

					$actions['deny'] = sprintf(
						'<a href="%s" class="urm-deny">%s <span>%s</span></a>',
						esc_url( $deny_link ),
						'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
							<path fill="#F25656" fill-rule="evenodd" d="M6 7a3 3 0 1 1 6 0 3 3 0 0 1-6 0Zm3-5a5 5 0 1 0 0 10A5 5 0 0 0 9 2ZM6 14a5 5 0 0 0-5 5v2a1 1 0 1 0 2 0v-2a3 3 0 0 1 3-3h6a3 3 0 0 1 3 3v2a1 1 0 1 0 2 0v-2a5 5 0 0 0-5-5H6Zm10.293-6.707a1 1 0 0 1 1.414 0L19.5 9.086l1.793-1.793a1 1 0 1 1 1.414 1.414L20.914 10.5l1.793 1.793a1 1 0 0 1-1.414 1.414L19.5 11.914l-1.793 1.793a1 1 0 0 1-1.414-1.414l1.793-1.793-1.793-1.793a1 1 0 0 1 0-1.414Z" clip-rule="evenodd"/>
						</svg>',
						__( 'Deny User', 'user-registration' )
					);
				}

				// 3. Send Password Reset
				$password_reset_link = add_query_arg(
					array(
						'action'   => 'resetpassword',
						'user_id'  => $user_id,
						'_wpnonce' => wp_create_nonce( 'bulk-users' ),
					),
					admin_url( 'admin.php?page=user-registration-users&view_user' ),
				);

				$actions['request_password_reset'] = sprintf(
					'<a href="%s" rel="noreferrer noopener" target="_blank">%s <p>%s</p></a>',
					esc_url( $password_reset_link ),
					'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
						<path fill="#000" fill-rule="evenodd" d="M12 2h-.004a10.75 10.75 0 0 0-7.431 3.021l-.012.012L4 5.586V3a1 1 0 1 0-2 0v5a.997.997 0 0 0 1 1h5a1 1 0 0 0 0-2H5.414l.547-.547A8.75 8.75 0 0 1 12.001 4 8 8 0 1 1 4 12a1 1 0 1 0-2 0A10 10 0 1 0 12 2Z" clip-rule="evenodd"/>
					</svg>',
					__( 'Send Password Reset Email', 'user-registration' )
				);

				// 4. Enable/Disable User
				$is_disabled = get_user_meta( $user_id, 'ur_disable_users', true );
				if ( empty( $is_disabled ) ) {
					update_user_meta( $user_id, 'ur_disable_users', false );
					$is_disabled = false;
				}
				// User is disabled.
				if ( $is_disabled ) {
					$enable_link             = add_query_arg(
						array(
							'action'   => 'enable_user',
							'user_id'  => $user_id,
							'_wpnonce' => wp_create_nonce( 'bulk-users' ),
						),
						admin_url( 'admin.php?page=user-registration-users&view_user' ),
					);
					$actions['disable_user'] = sprintf(
						'<a href="%s" >%s <p>%s</p></a>',
						esc_url( $enable_link ),
						'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
						<path fill="#000" fill-rule="evenodd" d="M12 2h-.004a10.75 10.75 0 0 0-7.431 3.021l-.012.012L4 5.586V3a1 1 0 1 0-2 0v5a.997.997 0 0 0 1 1h5a1 1 0 0 0 0-2H5.414l.547-.547A8.75 8.75 0 0 1 12.001 4 8 8 0 1 1 4 12a1 1 0 1 0-2 0A10 10 0 1 0 12 2Z" clip-rule="evenodd"/>
					</svg>
					  ',
						__( 'Enable User', 'user-registration' )
					);
				}
				// User is enabled
				if ( ! $is_disabled ) {
					$enable_link = add_query_arg(
						array(
							'action'   => 'enable_user',
							'user_id'  => $user_id,
							'_wpnonce' => wp_create_nonce( 'bulk-users' ),
						),
						admin_url( 'admin.php?page=user-registration-users&view_user' ),
					);

					$actions['disable_user'] = sprintf(
						'<a class="urm-deny" href="#">
							<div style=" cursor:pointer; display:flex" id="disable-user-link-%d" class="disable-user-link" data-nonce="%s">
								<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18" fill="none">
									<g clip-path="url(#clip0_3735_366)">
										<path d="M9 16.5C13.1421 16.5 16.5 13.1421 16.5 9C16.5 4.85786 13.1421 1.5 9 1.5C4.85786 1.5 1.5 4.85786 1.5 9C1.5 13.1421 4.85786 16.5 9 16.5Z" stroke="#F25656" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M11.25 6.75L6.75 11.25" stroke="#F25656" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M6.75 6.75L11.25 11.25" stroke="#F25656" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
									</g>
									<defs>
										<clipPath id="clip0_3735_366">
											<rect width="18" height="18" fill="white"/>
										</clipPath>
									</defs>
								</svg>
							</div>
							<span>%s</span>
						</a>',
						$user_id,
						wp_create_nonce( 'bulk-users' ),
						__( 'Disable User', 'user-registration' ),
					);
				}

				// 5. Delete User
				$delete_link = add_query_arg(
					array(
						'action'   => 'delete',
						'user_id'  => $user_id,
						'_wpnonce' => wp_create_nonce( 'bulk-users' ),
					),
					admin_url( 'admin.php?page=user-registration-users&view_user' ),
				);

				$wp_delete_url = add_query_arg(
					array(
						'user'     => $user_id,
						'_wpnonce' => wp_create_nonce( 'bulk_users' ),
					),
					admin_url( 'users.php?action=delete' )
				);

				$actions['delete'] = sprintf(
					'<a class="urm-deny" href="%s" data-wp-delete-url="%s">%s<span>%s</span></a>',
					esc_url( $delete_link ),
					esc_url_raw( $wp_delete_url ),
					'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18" fill="none">
						<g clip-path="url(#clip0_3735_144)">
							<path fill-rule="evenodd" clip-rule="evenodd" d="M6.96967 1.46967C7.11032 1.32902 7.30109 1.25 7.5 1.25H10.5C10.6989 1.25 10.8897 1.32902 11.0303 1.46967C11.171 1.61032 11.25 1.80109 11.25 2V2.75H6.75V2C6.75 1.80109 6.82902 1.61032 6.96967 1.46967ZM5.25 2.75V2C5.25 1.40326 5.48705 0.830966 5.90901 0.40901C6.33097 -0.0129471 6.90326 -0.25 7.5 -0.25H10.5C11.0967 -0.25 11.669 -0.0129471 12.091 0.40901C12.5129 0.830966 12.75 1.40326 12.75 2V2.75H14.25H15.75C16.1642 2.75 16.5 3.08579 16.5 3.5C16.5 3.91421 16.1642 4.25 15.75 4.25H15V14C15 14.5967 14.7629 15.169 14.341 15.591C13.919 16.0129 13.3467 16.25 12.75 16.25H5.25C4.65326 16.25 4.08097 16.0129 3.65901 15.591C3.23705 15.169 3 14.5967 3 14V4.25H2.25C1.83579 4.25 1.5 3.91421 1.5 3.5C1.5 3.08579 1.83579 2.75 2.25 2.75H3.75H5.25ZM6 4.25H12H13.5V14C13.5 14.1989 13.421 14.3897 13.2803 14.5303C13.1397 14.671 12.9489 14.75 12.75 14.75H5.25C5.05109 14.75 4.86032 14.671 4.71967 14.5303C4.57902 14.3897 4.5 14.1989 4.5 14V4.25H6ZM7.5 6.5C7.91421 6.5 8.25 6.83579 8.25 7.25V11.75C8.25 12.1642 7.91421 12.5 7.5 12.5C7.08579 12.5 6.75 12.1642 6.75 11.75V7.25C6.75 6.83579 7.08579 6.5 7.5 6.5ZM11.25 11.75V7.25C11.25 6.83579 10.9142 6.5 10.5 6.5C10.0858 6.5 9.75 6.83579 9.75 7.25V11.75C9.75 12.1642 10.0858 12.5 10.5 12.5C10.9142 12.5 11.25 12.1642 11.25 11.75Z" fill="#F25656"/>
						</g>
						<defs>
							<clipPath id="clip0_3735_144">
								<rect width="18" height="18" fill="white"/>
							</clipPath>
						</defs>
					</svg>',
					__( 'Delete User', 'user-registration' )
				);
			}

			$actions = apply_filters( 'user_registration_pro_user_actions', $actions, $user_id );

			$post_allowed                            = wp_kses_allowed_html( 'post' );
			$post_allowed['a']['data-wp-delete-url'] = true;
			$post_allowed['div']['data-nonce']       = true;

			$allowed = array_merge(
				$post_allowed,
				array(
					'svg'      => array(
						'class'           => true,
						'aria-hidden'     => true,
						'aria-labelledby' => true,
						'role'            => true,
						'xmlns'           => true,
						'width'           => true,
						'height'          => true,
						'viewbox'         => true, // <= Must be lower case!
						'fill'            => true,
					),
					'g'        => array(
						'fill'      => true,
						'clip-path' => true,
					),
					'path'     => array(
						'd'               => true,
						'fill'            => true,
						'fill-rule'       => true,
						'clip-rule'       => true,
						'stroke'          => true,
						'stroke-width'    => true,
						'stroke-linecap'  => true,
						'stroke-linejoin' => true,
					),
					'title'    => array( 'title' => true ),
					'defs'     => array(),
					'clippath' => array( 'id' => true ),
					'rect'     => array(
						'width'  => true,
						'height' => true,
						'fill'   => true,
					),
				)
			);

			if ( ! empty( $actions ) ) {
				?>
				<div class="sidebar-box" id="user-registration-user-view-user-actions">
					<ul>
						<?php
						foreach ( $actions as $key => $action_link ) {
							echo '<li id="user-registration-user-action-' . esc_attr( $key ) . '">' . wp_kses( $action_link, $allowed ) . '</li>';
						}
						?>
					</ul>
				</div>
				<?php
			}
		}

		/**
		 * Render extra information of the user.
		 *
		 * @param [int] $user_id User Id.
		 *
		 * @return void
		 * @since 4.1
		 */
		private function render_user_extra_details( $user_id ) {

			$user = get_userdata( $user_id );

			$user_manager          = new UR_Admin_User_Manager( $user );
			$is_temporary_disabled = get_user_meta( $user_id, 'ur_disable_users', true );

			$status = $user_manager->get_user_status();

			if ( ! empty( $status ) ) {
				$status = esc_html( UR_Admin_User_Manager::get_status_label( $status['user_status'] ) );
			}
			if ( $is_temporary_disabled ) {
				$status = 'Disabled';
			}
			$member_source = get_user_meta( $user_id, 'ur_registration_source', true );
			if ( 'membership' === $member_source && 'approved' === strtolower( $status ) ) {
				$order_status = apply_filters( 'user_registration_check_user_order_status', $user_id );
				if ( ! empty( $order_status ) && 'pending' === $order_status ) {
					$status = __( 'Payment Pending', 'user-registration' );
				}
			}
			$form_id      = ur_get_form_id_by_userid( $user_id );
			$form_title   = get_the_title( $form_id );
			$status_class = ( strtolower( $status ) === 'payment pending' ) ? 'pending' : $status;

			$extra_details = array(
				'user_id'         => array(
					'value' => $user_id,
				),
				'user_role'       => array(
					'value' => esc_html( implode( ', ', array_map( 'ucfirst', (array) $user->roles ) ) ),
				),
				'user_status'     => array(
					'value' => $status,
					'class' => 'user-registration-user-status-' . strtolower( $status_class ),
				),
				'registered_form' => array(
					'value' => $form_title,
				),
				'registered_on'   => array(
					'value' => $user->user_registered,
				),
			);

			/**
			 * Add details to show in extra details section.
			 *
			 * @since 4.1
			 */
			$extra_details = apply_filters( 'user_registration_single_user_view_extra_details', $extra_details, $user );

			if ( ! empty( $extra_details ) ) :
				?>
				<div class="urm-admin-user-content-container">
					<div id="urm-admin-user-content-header" >
						<h3>
							<?php
								esc_html_e( 'Entry Details', 'user-registration' );
							?>
						</h3>
					</div>
					<div class="user-registration-user-form-details">
						<table class="wp-list-table widefat fixed striped users">
							<thead>
								<tr>
									<th><?php esc_html_e( 'ID', 'user-registration' ); ?></th>
									<th><?php esc_html_e( 'Role', 'user-registration' ); ?></th>
									<th><?php esc_html_e( 'Status', 'user-registration' ); ?></th>
									<th><?php esc_html_e( 'Form', 'user-registration' ); ?></th>
									<th><?php esc_html_e( 'Date', 'user-registration' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<?php
									foreach ( $extra_details as $id => $data ) {
										?>
										<td class="<?php echo isset( $data['class'] ) ? esc_attr( $data['class'] ) : ''; ?>"><?php echo esc_html( $data['value'] ); ?></td>
										<?php
									}
									?>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
				<?php
			endif;
		}

		/**
		 * Render user form fields and their values.
		 *
		 * @param [int]  $user_id User Id.
		 * @param [bool] $display Either display the form or not.
		 *
		 * @return void
		 */
		private function render_user_form_fields( $user_id, $display ) {
			$user            = get_userdata( $user_id );
			$form_id         = ur_get_form_id_by_userid( $user_id );
			$form_data_array = ( $form_id ) ? UR()->form->get_form( $form_id, array( 'content_only' => true ) ) : array();

			$row_ids = array();
			if ( ! empty( $form_data_array ) ) {
				$row_ids       = get_post_meta( $form_id, 'user_registration_form_row_ids', true );
				$form_row_data = json_decode( get_post_meta( $form_id, 'user_registration_form_row_data', true ), true );
			}
			$row_ids = ! empty( $row_ids ) ? json_decode( $row_ids ) : array();

			?>
			<div class="urm-admin-user-content-container urm-admin-view-user <?php echo ! $display ? 'user-registration-hidden' : ''; ?>">
				<div id="urm-admin-user-content-header" >
					<h3><?php esc_html_e( 'Personal Information', 'user-registration' ); ?></h3>
					<?php
					if ( $form_id ) {
						?>
						<a id="user-registration-edit-user-link">
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
								<path d="M11.5397 14.6666H3.19301C2.69971 14.6666 2.22661 14.4706 1.87779 14.1218C1.52897 13.773 1.33301 13.2999 1.33301 12.8066V4.45992C1.33301 3.96662 1.52897 3.49352 1.87779 3.1447C2.22661 2.79588 2.69971 2.59992 3.19301 2.59992H7.36634C7.54315 2.59992 7.71272 2.67016 7.83775 2.79518C7.96277 2.9202 8.03301 3.08977 8.03301 3.26659C8.03301 3.4434 7.96277 3.61297 7.83775 3.73799C7.71272 3.86301 7.54315 3.93325 7.36634 3.93325H3.19301C3.05333 3.93325 2.91937 3.98874 2.8206 4.08751C2.72183 4.18628 2.66634 4.32024 2.66634 4.45992V12.8066C2.66634 12.9463 2.72183 13.0802 2.8206 13.179C2.91937 13.2778 3.05333 13.3333 3.19301 13.3333H11.5397C11.6794 13.3333 11.8133 13.2778 11.9121 13.179C12.0109 13.0802 12.0663 12.9463 12.0663 12.8066V8.66659C12.0663 8.48977 12.1366 8.32021 12.2616 8.19518C12.3866 8.07016 12.5562 7.99992 12.733 7.99992C12.9098 7.99992 13.0794 8.07016 13.2044 8.19518C13.3294 8.32021 13.3997 8.48977 13.3997 8.66659V12.8399C13.3909 13.3274 13.1911 13.792 12.8432 14.1336C12.4954 14.4753 12.0273 14.6667 11.5397 14.6666ZM5.73967 11.0666L8.12634 10.4733C8.24044 10.4396 8.34534 10.3803 8.43301 10.2999L14.0997 4.66659C14.2864 4.48875 14.4357 4.27537 14.5387 4.03898C14.6417 3.8026 14.6964 3.54799 14.6995 3.29016C14.7027 3.03232 14.6542 2.77646 14.557 2.53763C14.4598 2.29881 14.3157 2.08184 14.1334 1.89951C13.9511 1.71718 13.7341 1.57316 13.4953 1.47594C13.2565 1.37872 13.0006 1.33025 12.7428 1.3334C12.4849 1.33654 12.2303 1.39123 11.9939 1.49425C11.7576 1.59727 11.5442 1.74653 11.3663 1.93325L5.70634 7.59992C5.62165 7.68548 5.55976 7.79092 5.52634 7.90659L4.93301 10.2599C4.90479 10.3715 4.90592 10.4884 4.93629 10.5994C4.96667 10.7104 5.02525 10.8116 5.10634 10.8933C5.16863 10.955 5.24251 11.0039 5.32374 11.0371C5.40496 11.0703 5.49194 11.0871 5.57967 11.0866C5.63362 11.0864 5.68735 11.0797 5.73967 11.0666ZM12.313 2.83992C12.3973 2.75732 12.504 2.7014 12.6199 2.67916C12.7358 2.65691 12.8557 2.66934 12.9646 2.71488C13.0734 2.76041 13.1664 2.83704 13.232 2.93517C13.2975 3.0333 13.3327 3.14858 13.333 3.26659C13.3335 3.3446 13.3184 3.42193 13.2887 3.49405C13.2589 3.56616 13.215 3.63162 13.1597 3.68659L7.61967 9.21992L6.49301 9.50659L6.77967 8.37992L12.313 2.83992Z" fill="#6B6B6B"/>
							</svg>
							<span><?php esc_html_e( 'Edit', 'user-registration' ); ?></span>
						</a>
						<?php
					}
					?>
				</div>
				<div class="ur-frontend-form login ur-edit-profile">
					<div class="user-registration-user-body">
						<div class="user-registration-user-form-details">
							<?php if ( ! empty( $form_data_array ) ) : ?>
								<?php
								foreach ( $form_data_array as $index => $row_data ) {
									$row_id = $index;
									$ignore = false;
									if ( ! empty( $row_ids ) && isset( $row_ids[ $index ] ) ) {
										$row_id = absint( $row_ids[ $index ] );
									}

									if ( ! empty( $form_row_data ) ) {
										foreach ( $form_row_data as $key => $value ) {
											if ( $value['row_id'] == $row_id && isset( $value['type'] ) && 'repeater' === $value['type'] ) {
												$ignore = true;
											}
										}
									}

									if ( ! $ignore ) {
										echo '<div class="user-registration-user-row-details">';

										foreach ( $row_data as $grid_key => $grid_data ) {
											foreach ( $grid_data as $grid_data_key => $single_item ) {
												if ( ! isset( $single_item->general_setting->field_name ) ) {
													continue;
												}

												$field_name = $single_item->general_setting->field_name;
												$field_key  = isset( $single_item->field_key ) ? $single_item->field_key : '';

												/**
												 * Return fields to skip display in User view page.
												 *
												 * @since 4.1
												 */
												$skip_fields = apply_filters(
													'user_registration_single_user_view_skip_form_fields',
													array(
														'user_confirm_email',
														'user_pass',
														'user_confirm_password',
														'html',
														'section_title',
														'billing_address_title',
														'shipping_address_title',
														'profile_picture',
														'captcha',
														'multiple_choice',
														'single_item',
														'quantity_field',
														'stripe_gateway',
														'authorize_net_gateway',
														'total_field',
														'subscription_plan',
													)
												);

												if ( in_array( $field_key, $skip_fields, true ) ) {
													continue;
												}

												echo '<div class="single-field">';
												echo '<h3 class="single-field__label">' . esc_html( $single_item->general_setting->label ) . '</h3>';

												$value = '';

												$user_metadata_details = get_user_meta( $user->ID, 'user_registration_' . $field_name, true );

												if ( in_array(
													$field_key,
													array(
														'user_login',
														'user_email',
														'display_name',
														'user_url',
													),
													true
												) ) {
													$value = $user->$field_key;
												} elseif ( 'multi_select2' === $field_key ) {
													$values = get_user_meta( $user->ID, 'user_registration_' . $field_name, true );

													if ( ! empty( $values ) ) {
														$value = implode( ',', $values );
													}
												} elseif ( 'country' === $field_key ) {
													$value  = get_user_meta( $user->ID, 'user_registration_' . $field_name, true );
													$isJson = preg_match( '/^\{.*\}$/s', $value ) ? true : false;
													if ( $isJson ) {
														$country_data = json_decode( $value, true );
														$country_code = isset( $country_data['country'] ) ? $country_data['country'] : '';
														$state_code   = isset( $country_data['state'] ) ? $country_data['state'] : '';
														$value        = ur_format_country_field_data( $country_code, $state_code );
													} else {
														$country_class = ur_load_form_field_class( $field_key );
														$countries     = $country_class::get_instance()->get_country();
														$value         = isset( $countries[ $value ] ) ? $countries[ $value ] : $value;
													}
												} elseif ( 'signature' === $field_key ) {
													$value = get_user_meta( $user->ID, 'user_registration_' . $field_name, true );
													$value = wp_get_attachment_url( $value );
												} elseif ( 'membership' === $field_key ) {
													$membership_id = get_user_meta( $user->ID, 'user_registration_' . $field_name, true );
													$value         = get_the_title( $membership_id );
												} else {
													$value = get_user_meta( $user->ID, 'user_registration_' . $field_name, true );

													// For Woocommerce fields.
													$value = empty( $value ) ? get_user_meta( $user->ID, $field_name, true ) : $value;
												}

												$checkbox_fields = array(
													'checkbox',
													'privacy_policy',
													'mailerlite',
													'separate_shipping',
												);

												// Mark checkbox fields as Checked/Unchecked.
												if ( in_array( $field_key, $checkbox_fields, true ) ) {
													$value = is_array( $value ) ? implode( ', ', $value ) : esc_attr( $value );
												}

												// Display the default values in user entry page if field visibility is used.
												if ( ! metadata_exists( 'user', $user_id, 'user_registration_' . $field_name ) && ! in_array( $field_key, $skip_fields ) ) {
													$profile       = user_registration_form_data( $user_id, $form_id );
													$profile_index = 'user_registration_' . $field_name;

													if ( isset( $profile[ $profile_index ]['default'] ) ) {
														$default_value = $profile[ $profile_index ]['default'];

														if ( is_array( $default_value ) ) {
															$value = implode( ', ', $default_value );
														} else {
															$value = esc_html( $default_value );
														}
													} elseif ( metadata_exists( 'user', $user_id, $field_name ) ) {
														$value = get_user_meta( $user_id, $field_name, true );
													} else {
														$value = '';
													}

													if ( empty( $value ) && isset( $profile[ $profile_index ]['type'] ) && 'date' === $profile[ $profile_index ]['type'] ) {
														if ( isset( $profile[ $profile_index ]['custom_attributes']['data-default-date'] ) && 1 === absint( $profile[ $profile_index ]['custom_attributes']['data-default-date'] ) ) {
															$date_format = isset( $profile[ $profile_index ]['custom_attributes']['data-date-format'] ) ? $profile[ $profile_index ]['custom_attributes']['data-date-format'] : 'd/m/Y';
															$value       = date( $date_format, time() );
														}
													}
												}

												/**
												 * Modify value for the single field.
												 *
												 * @since 4.1
												 */
												$value = apply_filters( 'user_registration_single_user_view_field_value', $value, $field_name, $field_key );

												$non_text_fields = apply_filters(
													'user_registration_single_user_view_non_text_fields',
													array(
														'file',
													)
												);

												if ( is_string( $value ) && ! in_array( $field_key, $non_text_fields, true ) ) {
													if ( 'wysiwyg' === $field_key ) {
														echo wp_kses_post(
															'<div class="single-field__wysiwyg"> ' . html_entity_decode( $value ) . '</div>'
														);
													} elseif ( 'signature' === $field_key ) {
														echo wp_kses_post(
															'<div class="single-field__signature"><img src="' . esc_url( $value ) . '" width="100%" /></div>'
														);
													} elseif ( 60 > strlen( $value ) ) {
														printf(
															'<input type="text" class="%s" value="%s" disabled>',
															esc_attr( 'user_registration_edit_profile_' . $field_key ),
															esc_attr( $value )
														);
													} else {
														printf(
															'<textarea rows="6" disabled>%s</textarea>',
															esc_attr( $value )
														);
													}
												} else {
													$field_value = get_user_meta( $user_id, 'user_registration_' . $field_key, true );
													do_action( 'user_registration_single_user_view_output_' . $field_key . '_field', $user_id, $single_item, $field_value );
												}
												echo '</div>';
											}
										}
										echo '</div>';
									}

									do_action( 'user_registration_single_user_view_row_data', $row_id, $row_data, $form_id, $user_id );
								}
								?>
								<?php
							else :
								$image_url = esc_url( plugin_dir_url( UR_PLUGIN_FILE ) . 'assets/images/empty-table.png' );
								?>
							<div class="empty-list-table-container">
								<img src="<?php echo $image_url; ?>" alt="" />
							</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render user form fields and their values.
		 *
		 * @param [int]  $user_id User Id.
		 * @param [bool] $display Either display the form or not.
		 *
		 * @return void
		 */
		private function render_user_edit_form_fields( $user_id, $display ) {
			$user            = get_userdata( $user_id );
			$form_id         = ur_get_form_id_by_userid( $user_id );
			$form_data_array = ( $form_id ) ? UR()->form->get_form( $form_id, array( 'content_only' => true ) ) : array();
			$profile         = user_registration_form_data( $user_id, $form_id );

			do_action( 'user_registration_enqueue_scripts', $form_data_array, $form_id );

			// Prepare values.
			foreach ( $profile as $key => $field ) {
				$form_row_ids       = get_post_meta( $form_id, 'user_registration_form_row_ids', true );
				$form_row_ids_array = json_decode( $form_row_ids );
				if ( isset( $field['custom_attributes']['data-locale'] ) ) {
					if ( wp_script_is( 'flatpickr' ) && 'en' !== $field['custom_attributes']['data-locale'] ) {
						wp_enqueue_script( 'flatpickr-localization_' . $field['custom_attributes']['data-locale'], UR()->plugin_url() . '/assets/js/flatpickr/dist/I10n/' . $field['custom_attributes']['data-locale'] . '.js', array(), UR_VERSION, true );
					}
				}
				$value = get_user_meta( $user_id, $key, true );

				$profile[ $key ]['value'] = apply_filters( 'user_registration_my_account_edit_profile_field_value', $value, $key );
				$new_key                  = str_replace( 'user_registration_', '', $key );

				if ( in_array( $new_key, ur_get_registered_user_meta_fields() ) ) {
					$value                    = get_user_meta( $user_id, ( str_replace( 'user_', '', $new_key ) ), true );
					$profile[ $key ]['value'] = apply_filters( 'user_registration_my_account_edit_profile_field_value', $value, $key );
				} elseif ( isset( $user_data->$new_key ) && in_array( $new_key, ur_get_user_table_fields() ) ) {
					$profile[ $key ]['value'] = apply_filters( 'user_registration_my_account_edit_profile_field_value', $user_data->$new_key, $key );
				} elseif ( isset( $user_data->display_name ) && 'user_registration_display_name' === $key ) {
					$profile[ $key ]['value'] = apply_filters( 'user_registration_my_account_edit_profile_field_value', $user_data->display_name, $key );
				}
			}

			?>
			<div class="urm-admin-user-content-container urm-admin-edit-user <?php echo ! $display ? 'user-registration-hidden' : ''; ?>">
				<div id="urm-admin-user-content-header" >
					<h3><?php esc_html_e( 'Personal Information', 'user-registration' ); ?></h3>
				</div>
				<div class="ur-frontend-form login ur-edit-profile" id="ur-frontend-form">
					<?php
					if ( ! empty( $form_data_array ) ) :
						?>
						<div id="user-registration-edit-user-body">
							<form action="" class="edit-profile user-registration-EditProfileForm" method="post"
								enctype="multipart/form-data"
								data-form-id="<?php echo esc_attr( $form_id ); ?>"
								data-user-id="<?php echo esc_attr__( $user_id ); ?>">
								<div class="user-registration-edit-user-form-details">
									<div class="ur-form-grid">
										<?php
										foreach ( $form_data_array as $index => $data ) {
											$row_id = ( ! empty( $form_row_ids_array ) ) ? absint( $form_row_ids_array[ $index ] ) : $index;
											ob_start();
											echo '<div class="ur-form-row">';
											user_registration_edit_profile_row_template( $data, $profile );
											echo '</div>';
											$row_template = ob_get_clean();
											$row_template = apply_filters( 'user_registration_frontend_edit_profile_form_row_template', $row_template, $form_id, $profile, $row_id, $data );
											echo $row_template; // phpcs:ignore
										}
										?>
										<div class="ur-form-row edit-user-save-btn-container">
											<button class="button btn-primary save_user_details"
												type="button"><?php echo __( 'Save Changes', 'user-registration' ); ?>
												<span></span>
											</button>
										</div>

									</div>
								</div>
							</form>
						</div>
						<?php
					else :
						$image_url = esc_url( plugin_dir_url( UR_PLUGIN_FILE ) . 'assets/images/empty-table.png' );
						?>
						<div class="empty-list-table-container">
							<img src="<?php echo $image_url; ?>" alt="" />
						</div>
						<?php
					endif;
					?>
				</div>
			</div>
			<?php
		}
		/**
		 * Add or remove bulk items from the dropdown.
		 *
		 * @param [array] $bulk_array Array of bulk actions.
		 *
		 * @return array
		 * @since 4.1
		 */
		public function manage_bulk_action_items( $bulk_array ) {
			$new_actions = array(
				'approve'      => __( 'Approve', 'user-registration' ),
				'deny'         => __( 'Deny', 'user-registration' ),
				'update_role'  => __( 'Change role', 'user-registration' ),
				'resend_email' => __( 'Resend Verification Email', 'user-registration' ),
				'enable_user'  => __( 'Enable Users', 'user-registration' ),
			);

			$bulk_array = array_merge( $new_actions, $bulk_array );

			return $bulk_array;
		}

		/**
		 * Return asset URL.
		 *
		 * @param string $path Asset Path.
		 *
		 * @return string
		 */
		private static function get_asset_url( $path ) {
			/**
			 * Applies a filter to retrieve the URL of an asset (e.g., stylesheet or script).
			 *
			 * @param string $filter_name The name of the filter hook, 'user_registration_get_asset_url'.
			 * @param string $url The default URL of the asset, generated using plugins_url and the provided path.
			 * @param string $path The relative path to the asset within the plugin.
			 *
			 * @return string The filtered URL of the asset.
			 */
			return apply_filters( 'user_registration_get_asset_url', plugins_url( $path, UR_PLUGIN_FILE ), $path );
		}

		/**
		 * Render payment information of the user.
		 *
		 * @param [int] $user_id User Id.
		 * @param [int] $form_id Form Id.
		 *
		 * @return void
		 * @since 5.0
		 */
		public function render_user_payment_details( $user_id, $form_id ) {

			$payment_method = get_user_meta( $user_id, 'ur_payment_method', true );

			$user_source = get_user_meta( $user_id, 'ur_registration_source', true );

			if ( 'membership' === $user_source || '' !== $payment_method ) {
				$user_source = get_user_meta( $user_id, 'ur_registration_source', true );
				$total_items = array();

				if ( 'membership' === $user_source ) {
					$order_repository = new MembersOrderRepository();
					$total_items      = $order_repository->get_member_all_orders( $user_id );
				}

				$meta_value = get_user_meta( $user_id, 'ur_payment_invoices', true );

				if ( 'membership' !== $user_source && ! empty( $meta_value ) && is_array( $meta_value ) ) {
					foreach ( $meta_value as $values ) {
						$total_items[] = array(
							'user_id'        => $user_id,
							'transaction_id' => $values['invoice_no'] ?? '',
							'post_title'     => $values['invoice_plan'] ?? '',
							'status'         => get_user_meta( $user_id, 'ur_payment_status', true ),
							'created_at'     => $values['invoice_date'] ?? '',
							'type'           => get_user_meta( $user_id, 'ur_payment_type', true ),
							'payment_method' => str_replace( '_', ' ', get_user_meta( $user_id, 'ur_payment_method', true ) ),
							'total_amount'   => ( $values['invoice_amount'] ?? '' ),
							'currency'       => ( $values['invoice_currency'] ?? '' ),
						);
					}
				}

				if ( empty( $total_items ) ) {
					return;
				}

				ob_start();
				?>
				<div class="urm-admin-user-content-container">
					<div id="urm-admin-user-content-header" >
						<h3>
							<?php
							esc_html_e( 'Payments', 'user-registration' );
							?>
						</h3>
					</div>
					<div class="user-registration-user-form-details">
						<table class="wp-list-table widefat fixed striped users">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Transaction ID', 'user-registration' ); ?></th>
									<th><?php esc_html_e( 'Amount', 'user-registration' ); ?></th>
									<th><?php esc_html_e( 'Gateway', 'user-registration' ); ?></th>
									<th><?php esc_html_e( 'Status', 'user-registration' ); ?></th>
									<th><?php esc_html_e( 'Payment Date', 'user-registration' ); ?></th>
									<th><?php esc_html_e( 'Action', 'user-registration' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $total_items as $payment ) {
									$amount     = $payment['total_amount'];
									$currencies = ur_payment_integration_get_currencies();
									$currency   = isset( $payment['currency'] ) && '' !== $payment['currency'] ? $payment['currency'] : 'USD';

									$symbol = $currencies[ $currency ]['symbol'];
									$amount = ( ! empty( $currencies[ $currency ]['symbol_pos'] ) && 'left' === $currencies[ $currency ]['symbol_pos'] ) ? $symbol . number_format( $amount, 2 ) : number_format( $amount, 2 ) . $symbol;

									?>
									<tr>
										<td><?php echo esc_html( $payment['transaction_id'] ?? '' ); ?></td>
										<td><?php echo esc_html( $amount ); ?></td>
										<td><?php echo esc_html( $payment['payment_method'] ); ?></td>
										<td class="status-<?php echo esc_attr( $payment['status'] ); ?>"><?php echo esc_html( ucfirst( $payment['status'] ) ); ?></td>
										<td><?php echo ! empty( $payment['created_at'] ) ? esc_html( date_i18n( 'Y-m-d', strtotime( $payment['created_at'] ) ) ) : __( 'N/A', 'user-registration' ); ?></td>
										<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=member-payment-history&action=edit&id=' . ( $payment['ID'] ?? 0 ) ) ); ?>"><?php esc_html_e( 'View', 'user-registration' ); ?></a></td>
									</tr>
									<?php
								}
								?>
							</tbody>
						</table>
					</div>
				</div>
				<?php

				echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		public function user_registration_show_admin_notice() {
			if ( empty( $this->notice_data ) ) {
				return;
			}

			if ( 'error' === $this->notice_data['type'] ) {
				$error = $this->notice_data['error'];

				if ( ! is_wp_error( $error ) ) {
					$this->notice_data = array();
					return;
				}

				$message = $error->get_error_message();

				echo '<div class="notice ur-toaster ur-users-notice notice-error is-dismissible"><p>'
					. esc_html( $message )
					. '</p></div>';
			}

			$this->notice_data = array();
		}
	}
}

return new User_Registration_Members_Menu();
