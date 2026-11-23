<?php

/**
 * UserRegistration Pro Users Menu class.
 *
 * @package  UserRegistration/Admin
 * @author   WPEverest
 *
 * @since 4.1
 */

use WPEverest\URRepeaterFields\Frontend\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'User_Registration_Users_Menu' ) ) {
	/**
	 * User_Registration_Users_Menu class.
	 */
	class User_Registration_Users_Menu {

		/**
		 * Errors attribute.
		 *
		 * @var [array]
		 */
		private $errors;

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'admin_init', array( $this, 'include_files' ) );
			// Frontend Scripts

			add_action( 'admin_menu', array( $this, 'add_users_menu_tab' ), 60 );
			add_filter(
				'manage_user-registration-membership_page_user-registration-users_columns',
				array(
					$this,
					'get_column_headers',
				)
			);
			add_filter(
				'user_registration_users_table_column_headers',
				array(
					$this,
					'add_form_fields_columns',
				),
				10,
				1
			);

			add_action( 'load-user-registration-membership_page_user-registration-users', array( $this, 'add_screen_options' ) );
			add_filter(
				'set_screen_option_user_registration_page_user_registration_users_per_page',
				array(
					$this,
					'save_users_per_page_screen_option',
				),
				10,
				3
			);

			add_filter(
				'bulk_actions-user-registration-membership_page_user-registration-users',
				array(
					$this,
					'manage_bulk_action_items',
				)
			);
			add_action( 'admin_init', array( $this, 'handle_actions' ) );

			add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

			add_action( 'admin_notices', array( $this, 'handle_redirect_notices' ) );
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
						'title'                  => __( 'Delete User', 'user-registration' ),
						'confirm_message_single' => __( 'Are you sure you want to delete this user?', 'user-registration' ),
						'confirm_message_bulk'   => __( 'Are you sure you want to delete these users?', 'user-registration' ),
						'warning_message'        => __( 'All the user data and files will be permanently deleted.', 'user-registration' ),
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
		 * Renders the file upload field in the frontend.
		 *
		 * @param string $field List of fields.
		 * @param string $key Field key.
		 * @param array  $args Fields settings values.
		 * @param string $value Value saved in a field for a user.
		 */
		public function user_registration_form_field_file( $field, $key, $args, $value ) {

			$value             = isset( $args['value'] ) ? $args['value'] : '';
			$custom_attributes = array();

			if ( ! empty( $args['custom_attributes'] ) && is_array( $args['custom_attributes'] ) ) {
				foreach ( $args['custom_attributes'] as $attribute => $attribute_value ) {
					$custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
				}
			}

			/* Conditional Logic codes */
			$rules                      = array();
			$rules['conditional_rules'] = isset( $args['conditional_rules'] ) ? $args['conditional_rules'] : '';
			$rules['logic_gate']        = isset( $args['logic_gate'] ) ? $args['logic_gate'] : '';
			$rules['rules']             = isset( $args['rules'] ) ? $args['rules'] : array();
			$rules['required']          = isset( $args['required'] ) ? $args['required'] : '';

			foreach ( $rules['rules'] as $rules_key => $rule ) {
				if ( empty( $rule['field'] ) ) {
					unset( $rules['rules'][ $rules_key ] );
				}
			}

			$rules['rules'] = array_values( $rules['rules'] );

			$rules = ( ! empty( $rules['rules'] ) && isset( $args['enable_conditional_logic'] ) ) ? wp_json_encode( $rules ) : '';
			/*Conditonal Logic codes end*/

			$is_required = isset( $args['required'] ) ? $args['required'] : 0;

			?>
			<div class="urfu-file-upload" data-id="<?php echo esc_attr( $args['id'] ); ?>">
				<div class="form-row " id="<?php echo esc_attr( $key ); ?>_field" data-priority="">
					<label for="<?php echo esc_attr( $key ); ?>"
						class="ur-label"><?php echo esc_html( $args['label'] ); ?>
						<?php
						if ( $is_required ) {
							?>
							<abbr class="required"
								title="required">*
							</abbr>
							<?php
						}

						if ( isset( $args['tooltip'] ) && ur_string_to_bool( $args['tooltip'] ) ) {
							echo ur_help_tip( $args['tooltip_message'], false, 'ur-portal-tooltip' );
						}
						?>
					</label>
					<?php
					$attachment_ids = explode( ',', $value );
					$this->dropzone_file_upload_container( $rules, $args, $key, $value, $attachment_ids, $custom_attributes );

					if ( $args['description'] ) {
						echo '<span class="description">' . $args['description'] . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</div>
			</div>

			<?php

			return '';
		}

		/**
		 * Add Users submenu to User Registration Menus.
		 *
		 * @return void
		 * @since 4.1
		 */
		public function add_users_menu_tab() {
			add_submenu_page(
				'user-registration',
				__( 'User Registration Users', 'user-registration' ),
				__( 'Users', 'user-registration' ),
				'manage_user_registration',
				'user-registration-users',
				array(
					$this,
					'render_users_page',
				)
			);
		}

		/**
		 * Render the contents of Users page.
		 *
		 * @return void
		 * @since 4.1
		 */
		public function render_users_page() {

			if ( ! current_user_can( 'list_users' ) && ! current_user_can( 'manage_user_registration' ) ) {
				wp_die(
					'<h1>' . __( 'You need a higher level of permission.', 'user-registration' ) . '</h1>' .
						'<p>' . __( 'Sorry, you are not allowed to list users.', 'user-registration' ) . '</p>',
					403
				);
			}

			if ( isset( $_GET['view_user'] ) && isset( $_GET['user_id'] ) ) {
				$this->render_single_user_details();

				return;
			}

			add_screen_option( 'per_page' );

			include_once UR_ABSPATH . 'includes/admin/settings/class-ur-users-list-table.php';

			$list_table = new User_Registration_Users_List_Table();

			$list_table->prepare_items();
			?>
			<hr class="wp-header-end">
			<?php echo user_registration_plugin_main_header(); ?>
			<div id="user-registration-list-table-page" class="user-registration-users-page">
				<div class="user-registration-list-table-heading" bis_skin_checked="1">
					<h1>
						<?php echo esc_html__( 'All Users', 'user-registration' ); ?>
					</h1>
				</div>
				<div id="user-registration-list-filters-row">
					<?php
					$list_table->display_filters();
					?>
					<form method="get" id="user-registration-list-search-form">
						<input type="hidden" name="page" value="user-registration-users" />

						<?php
						$list_table->display_search_box();

						if ( ! empty( $_REQUEST['role'] ) ) {
							?>
							<input type="hidden" name="role" value="<?php echo esc_attr( $_REQUEST['role'] ); ?>" />
						<?php } ?>
					</form>
				</div>
				<hr>
				<form method="get" id="user-registration-users-action-form"
					class="user-registration-list-table-action-form">
					<input type="hidden" name="page" value="user-registration-users" />

					<?php if ( ! empty( $_REQUEST['role'] ) ) { ?>
						<input type="hidden" name="role" value="<?php echo esc_attr( $_REQUEST['role'] ); ?>" />
					<?php } ?>

					<?php $list_table->display(); ?>
				</form>
				<?php
				if ( isset( $_GET['form_filter'] ) ) {
					$form_id = (int) sanitize_text_field( wp_unslash( $_GET['form_filter'] ) );

					printf(
						"<input type='hidden' id='user-registration-users-form-id' value='%d'>",
						esc_attr( $form_id )
					);
				}
				?>
				<div class="clear"></div>
			</div>
			<?php
		}

		/**
		 * Render user single page content.
		 *
		 * @return void
		 * @since 4.1
		 */
		public function render_single_user_details() {

			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$user_id = absint( wp_unslash( $_REQUEST['user_id'] ) );
			$user    = get_userdata( $user_id );

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
					<?php $this->render_user_actions($user_id); //phpcs:ignore
					?>
					<?php $this->render_user_extra_details( $user_id ); ?>
					<?php
					/**
					 * Add more sections to the sidebar of user view page.
					 *
					 * @param int $user_id User Id.
					 */
					do_action( 'user_registration_user_view_sidebar', $user_id );
					?>
				</div>
				<?php
				if ( isset( $_GET['tab'] ) && 'user-actions' === $_GET['tab'] ) {
					?>
					<div id="user-registration-user-actions" class="user-registration-user-body">
						<?php $this->render_user_settings_section( $user_id ); ?>
					</div>
					<?php
				} elseif ( isset( $_GET['action'] ) && 'edit' === $_GET['action'] ) {
					$this->render_user_edit_form_fields( $user_id );
				} else {
					$this->render_user_form_fields( $user_id );
				}
				?>
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
			$user                = get_userdata( $user_id );
			$avatar              = get_avatar( $user_id, 900 );
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
					<p class="user-login">@<?php echo esc_html( $user->user_login ); ?> </p>
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
				if ( isset( $_GET['action'] ) && 'edit' == $_GET['action'] ) {
					$active_class = 'active';
				}
				$actions['edit'] = sprintf(
					'<a href="%s" rel="noreferrer noopener" class="%s" target="_self">%s <p>%s</p></a>',
					esc_url( $edit_link ),
					$active_class,
					'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
						<path fill="#000" fill-rule="evenodd" d="M19.207 3.207a1.121 1.121 0 0 1 1.586 1.586l-9.304 9.304-2.115.529.529-2.114 9.304-9.305ZM20 .88c-.828 0-1.622.329-2.207.914l-9.5 9.5a1 1 0 0 0-.263.465l-1 4a1 1 0 0 0 1.213 1.212l4-1a1 1 0 0 0 .464-.263l9.5-9.5A3.121 3.121 0 0 0 20 .88ZM4 3a3 3 0 0 0-3 3v14a3 3 0 0 0 3 3h14a3 3 0 0 0 3-3v-7a1 1 0 1 0-2 0v7a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h7a1 1 0 1 0 0-2H4Z" clip-rule="evenodd"/>
					</svg>',
					__( 'Edit User', 'user-registration' ),
				);

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
						'<a href="%s">%s <p>%s</p></a>',
						esc_url( $deny_link ),
						'<svg xmlns="http://www.w3.org/2000/svg" fill="#fff4f4" viewBox="0 0 24 24">
							<path fill="#000" fill-rule="evenodd" d="M6 7a3 3 0 1 1 6 0 3 3 0 0 1-6 0Zm3-5a5 5 0 1 0 0 10A5 5 0 0 0 9 2ZM6 14a5 5 0 0 0-5 5v2a1 1 0 1 0 2 0v-2a3 3 0 0 1 3-3h6a3 3 0 0 1 3 3v2a1 1 0 1 0 2 0v-2a5 5 0 0 0-5-5H6Zm10.293-6.707a1 1 0 0 1 1.414 0L19.5 9.086l1.793-1.793a1 1 0 1 1 1.414 1.414L20.914 10.5l1.793 1.793a1 1 0 0 1-1.414 1.414L19.5 11.914l-1.793 1.793a1 1 0 0 1-1.414-1.414l1.793-1.793-1.793-1.793a1 1 0 0 1 0-1.414Z" clip-rule="evenodd"/>
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
						'<a>
							<div style=" cursor:pointer; display:flex" id="disable-user-link-%d" class="disable-user-link" data-nonce="%s">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" style="margin-right: 8px;">
									<path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.707 13.707a1 1 0 0 1-1.414 0L12 13.414l-3.293 3.293a1 1 0 1 1-1.414-1.414L10.586 12 7.293 8.707a1 1 0 1 1 1.414-1.414L12 10.586l3.293-3.293a1 1 0 0 1 1.414 1.414L13.414 12l3.293 3.293a1 1 0 0 1 0 1.414z" fill="#ff0000"/>
								</svg>
								<span>%s</span>
							</div>
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
					'<a href="%s" rel="noreferrer noopener" target="_blank" data-wp-delete-url="%s">%s <p>%s</p></a>',
					esc_url( $delete_link ),
					esc_url_raw( $wp_delete_url ),
					'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
						<path fill="#000" fill-rule="evenodd" d="M9.293 3.293A1 1 0 0 1 10 3h4a1 1 0 0 1 1 1v1H9V4a1 1 0 0 1 .293-.707ZM7 5V4a3 3 0 0 1 3-3h4a3 3 0 0 1 3 3v1h4a1 1 0 1 1 0 2h-1v13a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V7H3a1 1 0 0 1 0-2h4Zm1 2h10v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V7h2Zm2 3a1 1 0 0 1 1 1v6a1 1 0 1 1-2 0v-6a1 1 0 0 1 1-1Zm5 7v-6a1 1 0 1 0-2 0v6a1 1 0 1 0 2 0Z" clip-rule="evenodd"/>
					</svg>',
					__( 'Delete User', 'user-registration' )
				);
			}

			$actions = apply_filters( 'user_registration_pro_user_actions', $actions, $user_id );

			if ( ! empty( $actions ) ) {
				?>
				<div class="sidebar-box" id="user-registration-user-view-user-actions">
					<h2 class="box-title">User Actions</h2>
					<ul>
						<?php
						foreach ( $actions as $key => $action_link ) {
							echo '<li id="user-registration-user-action-' . esc_attr( $key ) . '">' . wp_kses_post( $action_link ) . '</li>';
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
					'title' => __( 'User Id', 'user-registration' ),
					'value' => $user_id,
					'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
									<path fill="#000" fill-rule="evenodd" d="M21.707 1.293a1 1 0 0 1 0 1.414L20.414 4l2.293 2.293a1 1 0 0 1 0 1.414l-3.5 3.5a1 1 0 0 1-1.414 0L15.5 8.914l-2.751 2.751a6.5 6.5 0 1 1-1.414-1.414l3.457-3.457v-.001l.002-.001 3.497-3.497.002-.002.002-.002 1.998-1.998a1 1 0 0 1 1.414 0ZM19 5.414 16.914 7.5 18.5 9.086 20.586 7 19 5.414ZM7.5 11a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Z" clip-rule="evenodd"/>
								</svg>',
				),
				'user_status'     => array(
					'title' => __( 'User Status', 'user-registration' ),
					'value' => $status,
					'class' => 'user-registration-user-status-' . strtolower( $status_class ),
					'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
									<path fill="#000" fill-rule="evenodd" d="M4 3a1 1 0 0 0-2 0v18a1 1 0 0 0 1 1h18a1 1 0 1 0 0-2H4V3Zm15.707 5.293a1 1 0 0 0-1.414 0L14 12.586l-3.293-3.293a1 1 0 0 0-1.414 0l-3 3a1 1 0 1 0 1.414 1.414L10 11.414l3.293 3.293a1 1 0 0 0 1.414 0l5-5a1 1 0 0 0 0-1.414Z" clip-rule="evenodd"/>
								</svg>',

				),
				'user_role'       => array(
					'title' => __( 'User Role', 'user-registration' ),
					'value' => esc_html( ucfirst( implode( ' ', $user->roles ) ) ),
					'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
									<path fill="#000" fill-rule="evenodd" d="M9 4a3 3 0 1 0 0 6 3 3 0 0 0 0-6ZM4 7a5 5 0 1 1 10 0A5 5 0 0 1 4 7Zm12.969 6.286a1.999 1.999 0 0 0-.883 2.295 1.003 1.003 0 0 1 .2.45 2 2 0 0 0 2.295.883 1.002 1.002 0 0 1 .45-.2 2 2 0 0 0 .883-2.295 1.002 1.002 0 0 1-.2-.45 1.999 1.999 0 0 0-2.294-.883 1 1 0 0 1-.451.2Zm4.186-.745a4.022 4.022 0 0 0-.846-.808l.04-.117a1 1 0 0 0-1.898-.632l-.013.04a4.028 4.028 0 0 0-1.061.024l-.048-.12a1 1 0 0 0-1.857.743l.07.174c-.31.24-.582.526-.808.846l-.118-.04a1 1 0 0 0-.632 1.898l.04.013a4.03 4.03 0 0 0 .024 1.062l-.12.047a1 1 0 1 0 .743 1.857l.174-.069c.24.308.525.58.845.807l-.04.118a1 1 0 0 0 1.898.632l.014-.04a4.07 4.07 0 0 0 1.062-.024l.048.12a1 1 0 0 0 1.857-.743l-.07-.174c.309-.24.58-.526.807-.845l.118.039a1 1 0 0 0 .632-1.898l-.04-.013a4.04 4.04 0 0 0-.024-1.062l.12-.048a1 1 0 0 0-.743-1.857l-.174.07ZM6 14a5 5 0 0 0-5 5v2a1 1 0 1 0 2 0v-2a3 3 0 0 1 3-3h4a1 1 0 1 0 0-2H6Z" clip-rule="evenodd"/>
								</svg>',
				),
				'registered_form' => array(
					'title' => __( 'Form', 'user-registration' ),
					'value' => $form_title,
					'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
									<path fill="#000" fill-rule="evenodd" d="M3.879 1.879A3 3 0 0 1 6 1h8.5a1 1 0 0 1 .707.293l5.5 5.5A1 1 0 0 1 21 7.5V20a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V4a3 3 0 0 1 .879-2.121ZM6 3h7v5a1 1 0 0 0 1 1h5v11a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm9 4h3.086L15 3.914V7Zm-7 5a1 1 0 1 0 0 2h8a1 1 0 1 0 0-2H8Zm-1 5a1 1 0 0 1 1-1h8a1 1 0 1 1 0 2H8a1 1 0 0 1-1-1Zm1-9a1 1 0 0 0 0 2h2a1 1 0 1 0 0-2H8Z" clip-rule="evenodd"/>
								</svg>',
				),
				'registered_on'   => array(
					'title' => __( 'Date', 'user-registration' ),
					'value' => $user->user_registered,
					'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
									<path fill="#000" fill-rule="evenodd" d="M17 2a1 1 0 1 0-2 0v1H9V2a1 1 0 0 0-2 0v1H5a3 3 0 0 0-3 3v14a3 3 0 0 0 3 3h14a3 3 0 0 0 3-3V6a3 3 0 0 0-3-3h-2V2Zm3 7V6a1 1 0 0 0-1-1h-2v1a1 1 0 1 1-2 0V5H9v1a1 1 0 0 1-2 0V5H5a1 1 0 0 0-1 1v3h16ZM4 11h16v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-9Zm3 3a1 1 0 0 1 1-1h.01a1 1 0 1 1 0 2H8a1 1 0 0 1-1-1Zm5-1a1 1 0 1 0 0 2h.01a1 1 0 1 0 0-2H12Zm3 1a1 1 0 0 1 1-1h.01a1 1 0 1 1 0 2H16a1 1 0 0 1-1-1Zm-7 3a1 1 0 1 0 0 2h.01a1 1 0 1 0 0-2H8Zm3 1a1 1 0 0 1 1-1h.01a1 1 0 1 1 0 2H12a1 1 0 0 1-1-1Zm5-1a1 1 0 1 0 0 2h.01a1 1 0 1 0 0-2H16Z" clip-rule="evenodd"/>
								</svg>',
				),
			);

			/**
			 * Add details to show in extra details section.
			 *
			 * @since 4.1
			 */
			$extra_details = apply_filters( 'user_registration_single_user_view_extra_details', $extra_details, $user );

			$allowed_svg = array(
				'svg'  => array(
					'xmlns'   => true,
					'fill'    => true,
					'viewbox' => true,
					'width'   => true,
					'height'  => true,
					'class'   => true,
				),
				'path' => array(
					'fill'            => true,
					'fill-rule'       => true,
					'clip-rule'       => true,
					'd'               => true,
					'stroke'          => true,
					'stroke-width'    => true,
					'stroke-linecap'  => true,
					'stroke-linejoin' => true,
				),
			);

			if ( ! empty( $extra_details ) ) :
				?>
				<div class="sidebar-box" id="user-registration-user-view-extra-details">
					<h2 class="box-title"><?php esc_html_e( 'Extra Details', 'user-registration' ); ?></h2>
					<ul>
						<?php
						foreach ( $extra_details as $id => $data ) {
							printf(
								'<li id="%s">%s<p><span>%s:&nbsp;</span><span class="%s">%s</span></p></li>',
								esc_attr( 'user-registration-user-extra-detail-' . $id ),
								wp_kses( isset( $data['icon'] ) ? $data['icon'] : '', $allowed_svg ),
								esc_html( $data['title'] ),
								isset( $data['class'] ) ? esc_attr( $data['class'] ) : '',
								esc_html( $data['value'] )
							);
						}
						?>
					</ul>
				</div>
				<?php
			endif;
		}

		/**
		 * Render user form fields and their values.
		 *
		 * @param [int] $user_id User Id.
		 *
		 * @return void
		 */
		private function render_user_form_fields( $user_id ) {
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
											$value         = get_user_meta( $user->ID, 'user_registration_' . $field_name, true );
											$country_class = ur_load_form_field_class( $field_key );
											$countries     = $country_class::get_instance()->get_country();
											$value         = isset( $countries[ $value ] ) ? $countries[ $value ] : $value;
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
													'<input type="text" value="%s" disabled>',
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
				<?php do_action( 'user_registration_single_user_details_content', $user_id, $form_id ); ?>
			</div>
			<?php
		}

		/**
		 * Render user form fields and their values.
		 *
		 * @param [int] $user_id User Id.
		 *
		 * @return void
		 */
		private function render_user_edit_form_fields( $user_id ) {
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
			<?php
		}

		/**
		 * Returns the list of column headers for Users list table.
		 *
		 * @return array
		 * @since 4.1
		 */
		public function get_column_headers() {
			$column_headers = apply_filters(
				'user_registration_users_table_column_headers',
				array(
					'cb'              => '<input type="checkbox" />',
					'username'        => __( 'Username', 'user-registration' ),
					'fullname'        => __( 'Name', 'user-registration' ),
					'email'           => __( 'Email', 'user-registration' ),
					'role'            => __( 'Role', 'user-registration' ),
					'user_status'     => __( 'User Status', 'user-registration' ),
					'user_source'     => __( 'Source', 'user-registration' ),
					'user_registered' => __( 'Registered On', 'user-registration' ),

				)
			);

			$column_headers['actions'] = __( 'Actions', 'user-registration' );

			return $column_headers;
		}


		/**
		 * Add form specific columns to the screen column options.
		 *
		 * @param [array] $columns Columns array.
		 *
		 * @return array
		 */
		public function add_form_fields_columns( $columns ) {

			// Return early if no specific form is selected.
			if ( ! isset( $_GET['form_filter'] ) ) {
				return $columns;
			}

			$form_id = (int) sanitize_text_field( $_GET['form_filter'] ); //phpcs:ignore WordPress.Security.NonceVerification

			if ( $form_id ) {
				$form_data_array = ( $form_id ) ? UR()->form->get_form( $form_id, array( 'content_only' => true ) ) : array();

				foreach ( $form_data_array as $data ) {
					foreach ( $data as $grid_key => $grid_data ) {
						foreach ( $grid_data as $grid_data_key => $single_item ) {

							$field_label = $single_item->general_setting->label;
							$field_name  = $single_item->general_setting->field_name;

							$skip_fields = array(
								'user_login',
								'user_email',
								'user_confirm_email',
								'user_pass',
								'user_confirm_password',
							);

							if ( in_array( $field_name, $skip_fields ) ) {
								continue;
							}

							if ( ! empty( $field_name ) && ! empty( $field_label ) ) {
								$columns[ $field_name ] = $field_label;
							}
						}
					}
				}
			}

			return $columns;
		}

		/**
		 * Add screen options for Users table.
		 *
		 * @return void
		 * @since 4.1
		 */
		public function add_screen_options() {
			add_screen_option(
				'per_page',
				array(
					'label'   => 'Number of users per page',
					'default' => 10,
				)
			);
		}

		/**
		 * Updates the value of screen option for 'per_page' option.
		 *
		 * @param [string] $screen_option Default Screen option.
		 * @param [string] $option Option name.
		 * @param [string] $value User provided option value.
		 *
		 * @return string
		 * @since 4.1
		 */
		public function save_users_per_page_screen_option( $screen_option, $option, $value ) {

			if ( ! empty( $value ) && is_numeric( $value ) ) {
				$screen_option = intval( $value );
			}

			return $screen_option;
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

									$this->errors[] = new WP_Error( 'edit_users', __( "Sorry, you are not allowed to delete the user $user->user_login.", 'user-registration' ) );
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
							$role           = $_REQUEST['new_role'];

							if ( ! $role || empty( $editable_roles[ $role ] ) ) {
								$this->errors[] = new WP_Error( 'edit_users', __( 'Sorry, you are not allowed to give users that role.', 'user-registration' ) );
								break;
							}

							$role_change_count = 0;

							foreach ( $userids as $id ) {
								$id = (int) $id;

								$user = get_userdata( $id );

								if ( ! current_user_can( 'promote_user', $id ) ) {
									$this->errors[] = new WP_Error( 'edit_users', "Sorry, you are not allowed to change role for user {$user->user_login}." );
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

									$user_manager->set_approval( true );

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
							// Update user meta and redirect
							if ( $action === 'enable_user' ) {
								$value     = false;
								$message   = 'User enabled successfully.';
								$no_change = 'Selected Users are Already Enabled.';
								foreach ( $userids as $user_id ) {
									delete_user_meta( $user_id, 'ur_disable_users' );
									delete_user_meta( $user_id, 'ur_auto_enable_time' );
								}
							}
							if ( $action === 'disable_user' ) {
								$value     = true;
								$message   = 'User disabled successfully.';
								$no_change = 'Selected Users are Already Disabled.';
							}
							$enable_disable_count = 0;

							foreach ( $userids as $user_id ) {
								$enable_disable_user = update_user_meta( $user_id, 'ur_disable_users', $value );
								++$enable_disable_count;
							}
							if ( $enable_disable_user ) {
								add_action(
									'admin_notices',
									function () use ( $enable_disable_count, $message ) {
										printf(
											"<div class='updated notice ur-users-notice is-dismissible'><p>%s %s</p></div>",
											$enable_disable_count,
											isset( $message ) ? $message : '',
										);
									}
								);
							} else {
								add_action(
									'admin_notices',
									function () use ( $no_change ) {
										printf(
											"<div class='updated notice ur-users-notice is-dismissible'><p>%s</p></div>",
											isset( $no_change ) ? $no_change : '',
										);
									}
								);
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

									if ( current_user_can( 'manage_options' ) && $user_id === get_current_user_id() ) {
										$this->errors[] = new WP_Error( 'edit_users', __( 'Sorry, Admin cannot deny themselves.', 'user-registration' ) );
										continue;
									}
									$user_manager->set_approval( false );

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
						add_action(
							'admin_notices',
							function () use ( $error ) {
								echo '<div class="notice ur-toaster ur-users-notice notice-error is-dismissible"><p>' . esc_html( $error->get_error_message() ) . '</p></div>';
							}
						);
					}
				}
			}
		}

		/**
		 * Display Notices for actions that require redirections.
		 *
		 * @return void
		 */
		public function handle_redirect_notices() {
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
	}
}

return new User_Registration_Users_Menu();
