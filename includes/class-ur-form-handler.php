<?php
/**
 * Handle frontend forms.
 *
 * @class       UR_Form_Handler
 * @version     1.0.0
 * @package     UserRegistration/Classes/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * UR_Form_Handler Class.
 */
class UR_Form_Handler {


	/**
	 * Hook in methods.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'redirect_reset_password_link' ) );

		if ( ! ur_option_checked( 'user_registration_ajax_form_submission_on_edit_profile', false ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'save_profile_details' ) );
		}

		add_action( 'template_redirect', array( __CLASS__, 'save_change_password' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'process_login' ), 20 );
		add_action( 'wp_loaded', array( __CLASS__, 'process_registration' ), 20 );
		add_action( 'wp_loaded', array( __CLASS__, 'process_lost_password' ), 20 );
		add_action( 'wp_loaded', array( __CLASS__, 'process_reset_password' ), 20 );
		add_action( 'user_registration_before_customer_login_form', array( __CLASS__, 'export_confirmation_request' ) );
		add_action( 'user_registration_save_profile_details', array( __CLASS__, 'ur_update_user_ip_after_profile_update' ), 10, 2 );
		add_action( 'user_registration_force_logout_all_devices', array( __CLASS__, 'ur_force_logout_all_devices' ) );
	}

	/**
	 * Remove key and login from querystring, set cookie, and redirect to account page to show the form.
	 */
	public static function redirect_reset_password_link() {
		global $wp;

		self::maybe_restore_reset_password_cookie();

		if ( isset( $wp->query_vars['ur-lost-password'] ) && empty( $wp->query_vars['ur-lost-password'] ) ) {
			return;
		}
		$page_id                     = ur_get_page_id( 'myaccount' );
		$is_ur_login_or_account_page = ur_find_my_account_in_page( $page_id );

		$lost_password_page_id    = get_option( 'user_registration_lost_password_page_id', false );
		$is_ur_lost_password_page = ur_find_lost_password_in_page( $lost_password_page_id );

		if ( ( $is_ur_lost_password_page || $is_ur_login_or_account_page ) && ! empty( $_GET['key'] ) && ! empty( $_GET['login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$value = sprintf( '%s:%s', sanitize_text_field( wp_unslash( $_GET['login'] ) ), sanitize_text_field( wp_unslash( $_GET['key'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification

			if ( ! headers_sent() ) {
				nocache_headers();
			}

			UR_Shortcode_My_Account::set_reset_password_cookie( $value );

			$redirect_url = add_query_arg( 'show-reset-form', 'true', ur_resetpassword_url() );

			$token = wp_generate_password( 32, false );
			set_transient( 'ur_rp_' . $token, $value, HOUR_IN_SECONDS );
			$redirect_url = add_query_arg( 'urt', $token, $redirect_url );

			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Restore the reset-password cookie from a transient when a CDN strips it at the edge.
	 */
	private static function maybe_restore_reset_password_cookie() {
		$token = self::get_reset_password_handoff_token();

		if ( '' === $token ) {
			return;
		}

		// The opaque token lands in the URL, so keep it out of the Referer header.
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}

		$rp_cookie = 'wp-resetpass-' . COOKIEHASH;

		if ( ! empty( $_COOKIE[ $rp_cookie ] ) ) {
			return;
		}

		$value = get_transient( 'ur_rp_' . $token );

		if ( false !== $value ) {
			$_COOKIE[ $rp_cookie ] = $value;

			set_transient( 'ur_rp_' . $token, $value, HOUR_IN_SECONDS );
		}
	}

	/**
	 * @return string Validated [A-Za-z0-9] token from the current request, or ''.
	 */
	private static function get_reset_password_handoff_token() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only lookup; key is validated by check_password_reset_key().
		if ( empty( $_GET['show-reset-form'] ) || empty( $_GET['urt'] ) ) {
			return '';
		}

		$token = sanitize_text_field( wp_unslash( $_GET['urt'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return ctype_alnum( $token ) ? $token : '';
	}
	/**
	 * Save and update a profie fields if the form was submitted through the user account page.
	 *
	 * @return mixed
	 */
	public static function save_profile_details() {
		$profile_endpoint = get_option( 'user_registration_myaccount_edit_profile_endpoint', 'edit-profile' );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' !== strtoupper( wp_unslash( sanitize_key( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}

		if ( empty( $_POST['action'] ) || 'save_profile_details' !== $_POST['action'] || empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'save_profile_details' ) ) { //phpcs:ignore
			return;
		}

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		$form_id_array = get_user_meta( $user_id, 'ur_form_id' );
		$form_id       = 0;

		if ( isset( $form_id_array[0] ) ) {
			$form_id = $form_id_array[0];
		}
		// save profile details in case of non URM user.
		if ( $form_id === 0 ) {

			$user = wp_get_current_user();

			// upload the profile picture if it is set.
			$profile_pic_url                           = isset( $_POST['profile-pic-url'] ) ? sanitize_text_field( wp_unslash( $_POST['profile-pic-url'] ) ) : '';
			$valid_form_data                           = array();
			$valid_form_data['profile_pic_url']        = new stdClass();
			$valid_form_data['profile_pic_url']->value = $profile_pic_url;

			ur_upload_profile_pic( $valid_form_data, $user_id );

			$fields_to_update = array(
				'first_name',
				'last_name',
			);

			$userdata = array_combine(
				$fields_to_update,
				array_map(
					function ( $field ) {
						return sanitize_text_field( $_POST[ 'user_registration_' . $field ] );
					},
					$fields_to_update
				)
			);

			wp_update_user( array_merge( array( 'ID' => $user->ID ), $userdata ) );

			$new_email = sanitize_email( $_POST['user_registration_user_email'] );
			if ( $user->user_email !== $new_email ) {
				update_user_meta( $user->ID, '__pending_email', $new_email );
				$email_updated = true;
			}

			/**
			 * Filter to modify the profile update success message.
			 */
			$message = apply_filters( 'user_registration_profile_update_success_message', __( 'User profile updated successfully.', 'user-registration' ) );

			if ( $email_updated ) {
				self::send_confirmation_email( $user, $new_email, $form_id );
				/* translators: user_email */
				$user_email_update_message = sprintf( __( 'Your email address has not been updated yet. Please check your inbox at <strong>%s</strong> for a confirmation email.', 'user-registration' ), $pending_email );
				ur_add_notice( $user_email_update_message, 'notice' );
			}

			ur_add_notice( $message );

			wp_safe_redirect( ur_get_account_endpoint_url( $profile_endpoint ) );
			exit;
		} else {
			$profile         = user_registration_form_data( $user_id, $form_id );
			$form_field_data = ur_get_form_field_data( $form_id );
			$fields          = array();

			foreach ( $form_field_data as $field ) {
				$field_name = $field->general_setting->field_name;
				$key        = 'user_registration_' . $field_name;

				$field_obj             = new StdClass();
				$field_obj->field_name = $field_name;
				$fields[ $field_name ] = user_registration_sanitize_profile_update( $_POST, $field->field_key, $key );

				$field_obj->value = ur_clean( $fields[ $field_name ] );

				if ( isset( $field->field_key ) ) {
					$field_obj->field_type = $field->field_key;
				}

				if ( isset( $field->general_setting->label ) ) {
					$field_obj->label = $field->general_setting->label;
				}

				$fields[ $field_name ] = $field_obj;
			}

			list( $form_data, $_POST ) = apply_filters( 'user_registration_profile_update_data', array( $fields, $_POST ) );

			/**
			 * Action validate profile on update.
			 *
			 * @param array $profile The user profile data.
			 * @param array $form_data The form data.
			 * @param int $form_id The form ID.
			 * @param int $user_id The user id.
			 */
			do_action( 'user_registration_validate_profile_update', $profile, $form_data, $form_id, $user_id );

			/**
			 * Action validate profile on update.
			 *
			 * @param array $profile The user profile data.
			 * @param array $form_data The form data.
			 * @param int $form_id The form ID.
			 */
			do_action( 'user_registration_after_save_profile_validation', $user_id, $profile );

			if ( 0 === ur_notice_count( 'error' ) ) {
				$user_data = array();
				/**
				 * Hook to modify profile details before save.
				 *
				 * @param array $profile The profile data.
				 * @param int $user_id The user ID.
				 * @param int $form_id The form ID.
				 *
				 * @return array $profile
				 */
				$profile = apply_filters( 'user_registration_before_save_profile_details', $profile, $user_id, $form_id );

				/**
				 * Hook to modify confirmation email.
				 * Default value is true.
				 */
				$is_email_change_confirmation = (bool) apply_filters( 'user_registration_email_change_confirmation', true );
				$email_updated                = false;
				$pending_email                = '';
				$user                         = wp_get_current_user();
				foreach ( $profile as $key => $field ) {

					$new_key = str_replace( 'user_registration_', '', $key );

					if ( $is_email_change_confirmation && 'user_email' === $new_key ) {

						if ( $user ) {
							if ( !empty($_POST[ $key ]) && sanitize_email( wp_unslash( $_POST[ $key ] ) ) !== $user->user_email ) { // phpcs:ignore
								$email_updated = true;
								$pending_email = !empty($_POST[ $key ]) ? sanitize_email( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore
							}
							continue;
						}
					}

					if ( in_array( $new_key, ur_get_user_table_fields() ) ) {

						if ( 'display_name' === $new_key ) {
							$user_data['display_name'] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
						} else {
							$user_data[ $new_key ] = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						}
					} else {
						$update_key = $key;

						if ( in_array( $new_key, ur_get_registered_user_meta_fields(), true ) ) {
							$update_key = str_replace( 'user_', '', $new_key );
						}
						$disabled = isset( $field['custom_attributes']['disabled'] ) ? $field['custom_attributes']['disabled'] : '';
						if ( 'disabled' !== $disabled ) {
							if ( isset( $_POST[ $key ] ) ) {
								if ( isset( $field['field_key'] ) && 'file' !== $field['field_key'] ) {
									if ( 'signature' === $field['field_key'] ) {
										update_user_meta( $user_id, $update_key, apply_filters( 'user_registration_process_signature_field_data', $_POST[ $key ] ) );
									} else {
										update_user_meta( $user_id, $update_key, wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
									}
								} elseif ( isset( $field['type'] ) && 'repeater' === $field['type'] ) {
									update_user_meta( $user_id, $update_key, $form_data[ $key ]->value ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
								}
							} elseif ( isset( $field['field_key'] ) && 'checkbox' === $field['field_key'] ) {
								update_user_meta( $user_id, $update_key, '' );
							}
						}
					}
				}

				if ( count( $user_data ) > 0 ) {
					$user_data['ID'] = get_current_user_id();
					wp_update_user( $user_data );
				}

				/**
				 * Filter to modify the profile update success message.
				 */
				$message = apply_filters( 'user_registration_profile_update_success_message', __( 'User profile updated successfully.', 'user-registration' ) );

				if ( $email_updated ) {
					self::send_confirmation_email( $user, $pending_email, $form_id );
					/* translators: user_email */
					$user_email_update_message = sprintf( __( 'Your email address has not been updated yet. Please check your inbox at <strong>%s</strong> for a confirmation email.', 'user-registration' ), $pending_email );
					ur_add_notice( $user_email_update_message, 'notice' );
				}

				ur_add_notice( $message );
				/**
				 * Action save profile details.
				 *
				 * @param int $user_id The user ID.
				 * @param int $form_id The form ID.
				 */
				do_action( 'user_registration_save_profile_details', $user_id, $form_id );

				if ( 'country' === $field['field_key'] ) {
					foreach ( $fields as $field_key => $field_value ) {
						if ( $field_value->field_type === 'country' ) {
							update_user_meta( $user_id, 'user_registration_' . $field_value->field_name, $field_value->value );
						}
					}
				}
				wp_safe_redirect( ur_get_account_endpoint_url( $profile_endpoint ) );
				exit;
			}
		}
	}

	/**
	 * Send confirmation email.
	 *
	 * @param object $user User.
	 * @param email  $new_email Email.
	 * @param int    $form_id FormId.
	 * @return void
	 */
	public static function send_confirmation_email( $user, $new_email, $form_id ) {

		$from_name    = apply_filters( 'wp_mail_from_name', get_option( 'user_registration_email_from_name', esc_attr( get_bloginfo( 'name', 'display' ) ) ) );
		$sender_email = apply_filters( 'wp_mail_from', get_option( 'user_registration_email_from_address', get_option( 'admin_email' ) ) );
		$to           = $new_email;
		$template_id  = ur_get_single_post_meta( $form_id, 'user_registration_select_email_template' );
		$settings     = new UR_Settings_Confirm_Email_Address_Change_Email();
		$subject      = get_option( 'user_registration_confirm_email_address_change_email_subject', __( 'Confirm Your New Email Address', 'user-registration' ) );

		$username  = isset( $user->data->user_login ) ? sanitize_text_field( $user->data->user_login ) : '';
		$data_html = '<table class="user-registration-email__entries" cellpadding="0" cellspacing="0"><tbody>';
		$user_id   = isset( $user->ID ) ? sanitize_text_field( $user->ID ) : '';
		$form_id   = ur_get_form_id_by_userid( $user_id );

		$values = array(
			'username'           => $username,
			'user_email'         => $user->user_email,
			'all_fields'         => $data_html,
			'form_id'            => $form_id,
			'user_id'            => $user_id,
			'user_pending_email' => $new_email,
		);

		$name_value = array();

		$message     = $settings->ur_get_confirm_email_address_change_email();
		$message     = get_option( 'user_registration_confirm_email_address_change_email', $message );
		$template_id = ur_get_single_post_meta( $form_id, 'user_registration_select_email_template' );
		/**
		 * Filter to modify the change email content.
		 *
		 * @param string $message The message.
		 */
		$message = apply_filters( 'user_registration_email_change_email_content', $message );
		$message = UR_Emailer::parse_smart_tags( $message, $values, $name_value );
		$subject = UR_Emailer::parse_smart_tags( $subject, $values, $name_value );

		$headers = array(
			'From:' . $from_name . ' <' . $sender_email . '>',
			'Content-Type:text/html; charset=UTF-8',
		);

		$attachment = '';

		update_user_meta( $user->ID, 'user_registration_pending_email', $new_email );
		update_user_meta( $user->ID, 'user_registration_pending_email_expiration', time() + DAY_IN_SECONDS );
		if ( ur_option_checked( 'uret_override_confirm_email_address_change_email', true ) ) {
			list( $message, $subject ) = user_registration_email_content_overrider( $form_id, $settings, $message, $subject );
			$message                   = UR_Emailer::parse_smart_tags( $message, $values, $name_value );
			$subject                   = UR_Emailer::parse_smart_tags( $subject, $values, $name_value );

			UR_Emailer::user_registration_process_and_send_email( $to, $subject, $message, $headers, $attachment, $template_id );
		} else {
			UR_Emailer::user_registration_process_and_send_email( $to, $subject, $message, $headers, $attachment, $template_id );
		}
	}

	/**
	 * This format returns form field data in object format.
	 *
	 * In Non-ajax method of update profile, form data is received in key => value format
	 * which is different from the data received while using ajax submission.
	 *
	 * So, to maintain consistency of form data object while passing to different functions,
	 * data is formatted properly.
	 *
	 * @param [int] $form_id Form Id.
	 * @return array
	 */
	public static function get_form_data_from_post( $form_id ) {

		$form_field_data = ur_get_form_field_data( $form_id );

		$fields = array();

		foreach ( $form_field_data as $field ) {
			$field_name = $field->general_setting->field_name;
			$key        = 'user_registration_' . $field_name;

			$field_obj             = new StdClass();
			$field_obj->field_name = $field_name;

			$value = '';

			switch ( $field->field_key ) {
				case 'checkbox':
					if ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) { // phpcs:ignore
						$value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore
					} else {
						$value = (int) isset( $_POST[ $key ] ); // phpcs:ignore
					}
					break;

				case 'wysiwyg':
					if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore
						$value = sanitize_text_field( htmlentities( wp_unslash( $_POST[ $key ] ) ) ); // phpcs:ignore
					} else {
						$value = '';
					}
					break;

				case 'email':
					if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore
						$value = sanitize_email( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore
					} else {
						$user_id   = get_current_user_id();
						$user_data = get_userdata( $user_id );
						$value     = $user_data->data->user_email;
					}
					break;
				case 'profile_picture':
					if ( isset( $_POST['profile_pic_url'] ) ) { // phpcs:ignore
						$value = sanitize_text_field( wp_unslash( $_POST['profile_pic_url'] ) ); // phpcs:ignore
					} else {
						$value = '';
					}
					break;
				case 'coupon':
					$value = json_encode( $field->advance_setting );
					break;

				default:
					$value = isset( $_POST[ $key ] ) ? $_POST[ $key ] : ''; // phpcs:ignore
					break;
			}

			$field_obj->value = ur_clean( $value );

			if ( isset( $field->field_key ) ) {
				$field_obj->field_type = $field->field_key;
			}

			if ( isset( $field->general_setting->label ) ) {
				$field_obj->label = $field->general_setting->label;
			}

			$fields[ $field_name ] = $field_obj;
		}
		return $fields;
	}

	/**
	 * Delete a pending email change.
	 *
	 * @param integer $user_id User ID.
	 * @return void
	 */
	public static function delete_pending_email_change( $user_id ) {
		delete_user_meta( $user_id, 'user_registration_email_confirm_key' );
		delete_user_meta( $user_id, 'user_registration_pending_email' );
		delete_user_meta( $user_id, 'user_registration_pending_email_expiration' );
	}

	/**
	 * Save the password and redirect back to the my account page.
	 */
	public static function save_change_password() {
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}

		$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : null;  // phpcs:ignore WordPress.Security.NonceVerification

		if ( empty( $action ) || 'save_change_password' !== $_POST['action'] || empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'save_change_password' ) ) { // phpcs:ignore
			return;
		}

		$errors = new WP_Error();
		$user   = new stdClass();

		$user->ID     = (int) get_current_user_id();
		$current_user = get_user_by( 'id', $user->ID );

		if ( $user->ID <= 0 ) {
			return;
		}

		$pass_cur                = ! empty( $_POST['password_current'] ) ? $_POST['password_current'] : '';//phpcs:ignore;
		$pass1                   = ! empty( $_POST['password_1'] ) ? $_POST['password_1'] : '';//phpcs:ignore;
		$pass2                   = ! empty( $_POST['password_2'] ) ? $_POST['password_2'] : '';//phpcs:ignore;
		$save_pass = true;
		/**
		 * Filter hook to modify the save account bypass currect passoword.
		 * Default value is false.
		 */
		$bypass_current_password = apply_filters( 'user_registration_save_account_bypass_current_password', false );

		if ( empty( $pass_cur ) && empty( $pass1 ) && empty( $pass2 ) ) {
			ur_add_notice( __( 'Please fill out all password fields.', 'user-registration' ), 'error' );
			$save_pass = false;
		} elseif ( ! $bypass_current_password && empty( $pass_cur ) ) {
			ur_add_notice( __( 'Please enter your current password.', 'user-registration' ), 'error' );
			$save_pass = false;
		} elseif ( empty( $pass1 ) ) {
			ur_add_notice( __( 'Please enter your new password.', 'user-registration' ), 'error' );
			$save_pass = false;
		} elseif ( empty( $pass2 ) ) {
			ur_add_notice( __( 'Please re-enter your password.', 'user-registration' ), 'error' );
			$save_pass = false;
		} elseif ( $pass1 !== $pass2 ) {
			ur_add_notice( __( 'New passwords do not match.', 'user-registration' ), 'error' );
			$save_pass = false;
		} elseif ( ! $bypass_current_password && ! wp_check_password( $pass_cur, $current_user->user_pass, $current_user->ID ) ) {
			ur_add_notice( __( 'Your current password is incorrect.', 'user-registration' ), 'error' );
			$save_pass = false;
		} elseif ( wp_check_password( $pass1, $current_user->user_pass, $current_user->ID ) && $current_user ) {
			ur_add_notice( __( 'New password must not be same as old password', 'user-registration' ), 'error' );
			$save_pass = false;
		}

		if ( $pass1 && $save_pass ) {
			$user->user_pass = $pass1;
		}

		/**
		 * Fires an action hook to handle errors during the saving of user registration account details.
		 *
		 * @param string $hook_name The name of the action hook, 'user_registration_save_account_details_errors'.
		 * @param array  $args      An array containing references to the errors and user data to be passed to hooked functions.
		 *                          - &$errors (array) An array of errors encountered during account details saving.
		 *                          - &$user   (object) Reference to the user data being processed during account details saving.
		 */
		do_action_ref_array( 'user_registration_save_account_details_errors', array( &$errors, &$user ) );

		if ( $errors->get_error_messages() ) {
			foreach ( $errors->get_error_messages() as $error ) {
				ur_add_notice( $error, 'error' );
			}
		}

		if ( ur_notice_count( 'error' ) === 0 ) {

			wp_update_user( $user );
			$force_logout = apply_filters( 'user_registration_force_logout_after_password_change', true );

			/**
			 * Fires an action hook after successfully saving user registration account details.
			 *
			 * @param string $hook_name The name of the action hook, 'user_registration_save_account_details'.
			 * @param int    $user_id   The ID of the user whose account details have been successfully saved.
			 */
			if ( $force_logout ) {
				do_action( 'user_registration_force_logout_all_devices', $user->ID );
			} else {
				ur_add_notice( __( 'Password changed successfully.', 'user-registration' ) );
				do_action( 'user_registration_save_account_details', $user->ID );
				wp_safe_redirect( ur_get_page_permalink( 'myaccount' ) );
			}
			exit;
		}
	}

	/**
	 * Process the login form.
	 *
	 * @throws Exception Login errors.
	 */
	public static function process_login() {
		$nonce_value = isset( $_POST['_wpnonce'] ) ? sanitize_key( $_POST['_wpnonce'] ) : '';
		$nonce_value = isset( $_POST['user-registration-login-nonce'] ) ? sanitize_key( $_POST['user-registration-login-nonce'] ) : $nonce_value;

		if ( ! empty( $_POST['login'] ) && wp_verify_nonce( $nonce_value, 'user-registration-login' ) ) {
			ur_process_login( $nonce_value );
		}
	}

	/**
	 * Process the login form.
	 *
	 * @throws Exception Login errors.
	 */
	public static function process_registration() {
		$nonce_value = isset( $_POST['_wpnonce'] ) ? sanitize_key( $_POST['_wpnonce'] ) : '';

		$nonce_value = isset( $_POST['ur_frontend_form_nonce'] ) ? sanitize_key( $_POST['ur_frontend_form_nonce'] ) : $nonce_value;

		if ( ! empty( $_POST['ur_fallback_submit'] ) ) {
			$_POST['form_data'] = urldecode( $_POST['form_data'] );
			ur_process_registration( $nonce_value );
		}
	}

	/**
	 * Handle lost password form.
	 */
	public static function process_lost_password() {
		if ( isset( $_POST['ur_reset_password'] ) && isset( $_POST['user_login'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'lost_password' ) ) {

			$recaptcha_value = isset( $_POST['g-recaptcha-response'] ) ? ur_clean( sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) ) : '';
			/**
			 * Filter to modify the lost password options enable recaptcha.
			 * Default value is false.
			 */
			$recaptcha_enabled   = ur_string_to_bool( apply_filters( 'user_registration_lost_password_options_enable_recaptcha', false ) );
			$recaptcha_type      = get_option( 'user_registration_captcha_setting_recaptcha_version', 'v2' );
			$invisible_recaptcha = ur_option_checked( 'user_registration_captcha_setting_invisible_recaptcha_v2', false );

			$recaptcha_type = apply_filters( 'user_registration_lost_password_captcha_type', $recaptcha_type );

			if ( 'v2' === $recaptcha_type && ! $invisible_recaptcha ) {
				$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key' );
				$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret' );
			} elseif ( 'v2' === $recaptcha_type && $invisible_recaptcha ) {
				$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_invisible_site_key' );
				$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_invisible_site_secret' );
			} elseif ( 'v3' === $recaptcha_type ) {
				$site_key   = get_option( 'user_registration_captcha_setting_recaptcha_site_key_v3' );
				$secret_key = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_v3' );
			} elseif ( 'hCaptcha' === $recaptcha_type ) {
				$recaptcha_value = isset( $_POST['h-captcha-response'] ) ? ur_clean( sanitize_text_field( wp_unslash( $_POST['h-captcha-response'] ) ) ) : '';
				$site_key        = get_option( 'user_registration_captcha_setting_recaptcha_site_key_hcaptcha' );
				$secret_key      = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_hcaptcha' );
			} elseif ( 'cloudflare' === $recaptcha_type ) {
				$recaptcha_value = isset( $_POST['cf-turnstile-response'] ) ? ur_clean( sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) ) : '';
				$site_key        = get_option( 'user_registration_captcha_setting_recaptcha_site_key_cloudflare' );
				$secret_key      = get_option( 'user_registration_captcha_setting_recaptcha_site_secret_cloudflare' );
			}

			if ( $recaptcha_enabled && ! empty( $site_key ) && ! empty( $secret_key ) ) {
				if ( ! empty( $recaptcha_value ) ) {
					if ( 'hCaptcha' === $recaptcha_type ) {
						$data = wp_remote_get( 'https://hcaptcha.com/siteverify?secret=' . $secret_key . '&response=' . $recaptcha_value );
						$data = json_decode( wp_remote_retrieve_body( $data ) );
						/**
						 * Filter to modify the hcaptch threshold value.
						 * Default value is 0.5
						 */
						if ( empty( $data->success ) || ( isset( $data->score ) && $data->score < apply_filters( 'user_registration_hcaptcha_threshold', 0.5 ) ) ) {
							ur_add_notice( __( 'Error on hCaptcha. Contact your site administrator.', 'user-registration' ), 'error' );
							return false;
						}
					} elseif ( 'cloudflare' === $recaptcha_type ) {
						$url    = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
						$params = array(
							'method' => 'POST',
							'body'   => array(
								'secret'   => $secret_key,
								'response' => $recaptcha_value,
							),
						);
						$data   = wp_safe_remote_post( $url, $params );
						$data   = json_decode( wp_remote_retrieve_body( $data ) );

						if ( empty( $data->success ) ) {
							ur_add_notice( __( 'Error on Cloudflare Turnstile. Contact your site administrator.', 'user-registration' ), 'error' );
							return false;
						}
					} else {
						/**
						 * Filter to modify the recaptcha domain.
						 * Default value is https://www.google.com/recaptcha
						 */
						$url  = apply_filters( 'user_registration_recaptcha_domain', 'https://www.google.com/recaptcha/' );
						$data = wp_remote_get( $url . 'api/siteverify?secret=' . $secret_key . '&response=' . $recaptcha_value );
						$data = json_decode( wp_remote_retrieve_body( $data ) );
						/**
						 * Filter to modify the recaptcha v3 threshold score.
						 * Default value is 0.5
						 */
						if ( empty( $data->success ) || ( isset( $data->score ) && $data->score <= get_option( 'user_registration_captcha_setting_recaptcha_threshold_score_v3', apply_filters( 'user_registration_recaptcha_v3_threshold', 0.5 ) ) ) ) {
							ur_add_notice( __( 'Error on google reCaptcha. Contact your site administrator.', 'user-registration' ), 'error' );
							return false;
						}
					}
				} else {
					ur_add_notice( get_option( 'user_registration_form_submission_error_message_recaptcha', __( 'Captcha code error, please try again.', 'user-registration' ) ), 'error' );
					return false;
				}
			}

			$result = UR_Shortcode_My_Account::retrieve_password();

			if ( true === $result ) {
				wp_redirect(
					add_query_arg(
						'reset-link-sent',
						'true',
						remove_query_arg(
							array(
								'key',
								'login',
								'reset',
							)
						)
					)
				);
				exit;
			} else {

				$lost_password_page_id = get_option( 'user_registration_lost_password_page_id', false );

				if ( $lost_password_page_id && ! empty( get_post( $lost_password_page_id ) ) ) {
					$lost_password_url = get_permalink( $lost_password_page_id );
				} else {
					$lost_password_url = home_url( '/' );
				}
				$allowed_error_types = array(
					'empty',
					'blocked',
					'invalid',
					'not_allowed',
					'email_failed',
				);

				$error_type = isset( $result['error_type'] ) ? sanitize_key( $result['error_type'] ) : 'invalid';
				$error_type = in_array( $error_type, $allowed_error_types, true ) ? $error_type : 'invalid';

				$error_message = isset( $result['message'] ) ? sanitize_text_field( $result['message'] ) : '';

				wp_redirect(
					esc_url_raw(
						add_query_arg(
							array(
								'ur-lp-error' => $error_type,
								'message'     => rawurlencode( $error_message ),
							),
							$lost_password_url
						)
					)
				);
				exit;
			}
		}
	}

	/**
	 * Handle reset password form.
	 */
	public static function process_reset_password() {
		$posted_fields = array(
			'ur_reset_password',
			'password_1',
			'password_2',
			'reset_key',
			'reset_login',
			'_wpnonce',
		);

		foreach ( $posted_fields as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				return;
			}
			$posted_fields[$field] = $_POST[$field]; // phpcs:ignore
		}

		if ( ! wp_verify_nonce( $posted_fields['_wpnonce'], 'reset_password' ) ) {
			return;
		}

		$user = UR_Shortcode_My_Account::check_password_reset_key( $posted_fields['reset_key'], $posted_fields['reset_login'] );

		if ( $user instanceof WP_User ) {
			if ( empty( $posted_fields['password_1'] ) ) {
				$err_msg = apply_filters( 'user_registration_reset_password_error_message', __( 'Please enter your password.', 'user-registration' ) );
				ur_add_notice( $err_msg, 'error' );
			}

			if ( $posted_fields['password_1'] !== $posted_fields['password_2'] ) {
				$err_msg = apply_filters( 'user_registration_reset_password_error_message', __( 'New passwords do not match.', 'user-registration' ) );
				ur_add_notice( $err_msg, 'error' );
			}

			if ( wp_check_password( $posted_fields['password_1'], $user->user_pass, $user->ID ) ) {
				$err_msg = apply_filters( 'user_registration_reset_password_error_message', __( 'New password must not be same as old password.', 'user-registration' ) );
				ur_add_notice( $err_msg, 'error' );
			}

			$errors = new WP_Error();
			/**
			 * Fires an action hook to validate a password reset attempt.
			 *
			 * @param string $hook_name The name of the action hook, 'validate_password_reset'.
			 * @param array  $errors    An array of errors encountered during the password reset validation.
			 * @param object $user      The user object for the password reset attempt.
			 */
			do_action( 'validate_password_reset', $errors, $user );

			ur_add_wp_error_notices( $errors );

			if ( 0 === ur_notice_count( 'error' ) ) {
				UR_Shortcode_My_Account::reset_password( $user, $posted_fields['password_1'] );
				/**
				 * Fires an action hook after resetting the password for a user in the user registration process.
				 *
				 * @param string $hook_name The name of the action hook, 'user_registration_reset_password'.
				 * @param object $user      The user object for whom the password has been reset.
				 */
				do_action( 'user_registration_reset_password', $user );

				// Single-use: drop the CDN-proof transient handoff token now the reset succeeded.
				$handoff_token = self::get_reset_password_handoff_token();
				if ( '' !== $handoff_token ) {
					delete_transient( 'ur_rp_' . $handoff_token );
				}

				$ur_account_page_exists   = ur_get_page_id( 'myaccount' ) > 0;
				$ur_login_or_account_page = ur_get_page_permalink( 'myaccount' );

				if ( ! $ur_account_page_exists ) {
					$ur_login_or_account_page = ur_get_page_permalink( 'login' );
				}

				set_transient( 'ur_password_resetted_flag', true, 60 );

				$redirect = add_query_arg( 'password-reset', 'true', $ur_login_or_account_page );
				$redirect = apply_filters( 'user_registration_reset_password_redirect', $redirect, $user );

				wp_redirect( $redirect );
				exit;
			}
		}
	}

	/**
	 * Handle Export Personal data confirmation request.
	 */
	public static function export_confirmation_request() {
		if ( isset( $_REQUEST['action'] ) && 'confirmaction' === $_REQUEST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( $_GET['request_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}

			$request_id = (int) $_GET['request_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( isset( $_GET['confirm_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$key    = sanitize_text_field( wp_unslash( $_GET['confirm_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$result = wp_validate_user_request_key( $request_id, $key );
			} else {
				$result = new WP_Error( 'invalid_key', __( 'Invalid Key', 'user-registration' ) );
			}

			if ( is_wp_error( $result ) ) {
				ur_add_notice( $result->get_error_message(), 'error' );
				ur_print_notices();
				return;
			}
			/**
			 * Fires an action hook after confirming a user request action.
			 *
			 * @param string $hook_name The name of the action hook, 'user_request_action_confirmed'.
			 * @param int    $request_id The ID of the user request that has been confirmed.
			 */

			do_action( 'user_request_action_confirmed', $request_id );

			$request = wp_get_user_request( $request_id );

			if ( $request && in_array( $request->action_name, _wp_privacy_action_request_types(), true ) ) {
				if ( 'export_personal_data' === $request->action_name ) {
					/**
					 * Filter to modify export personal data confirmation message.
					 */
					$message = apply_filters( 'user_registration_export_personal_data_confirmation_message', __( 'Thanks for confirming your export request.', 'user-registration' ) );
				} elseif ( 'remove_personal_data' === $request->action_name ) {
					/**
					 * Filter to modify remove personal data confirmation message.
					 */
					$message = apply_filters( 'user_registration_remove_personal_data_confirmation_message', __( 'Thanks for confirming your erasure request.', 'user-registration' ) );
				}
				ur_add_notice( $message, 'success' );
				ur_print_notices();
			}
		}
	}

	/**
	 * Update the user's IP address in form data if not already present.
	 *
	 * @since  3.0.4.1
	 *
	 * @param int $user_id The ID of the User.
	 * @param int $form_id   The ID of the form.
	 */
	public static function ur_update_user_ip_after_profile_update( $user_id, $form_id ) {
		$user_ip = ur_get_ip_address();
		update_user_meta( $user_id, 'ur_user_ip', $user_ip );
	}

	/**
	 * Force logout all devices for a user.
	 *
	 * @param int $user_id The ID of the user.
	 */
	public static function ur_force_logout_all_devices( $user_id ) {

		if ( class_exists( 'WP_Session_Tokens' ) ) {
			$session_tokens = WP_Session_Tokens::get_instance( $user_id );
			$session_tokens->destroy_all();
			$url = ur_get_page_permalink( 'myaccount' );
			$url = add_query_arg(
				array(
					'force-logout' => 'true',

				),
				$url
			);
			wp_safe_redirect( esc_url( $url ) );

		}
	}

	/**
	 * Save Account Details.
	 *
	 * @deprecated 1.4.1
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function save_account_details( $user_id ) {
		ur_deprecated_function( 'UR_Form_Handler::save_account_details', '1.4.1', 'UR_Form_Handler::save_change_password' );
	}

	/**
	 * Create and return a dictionary of field_id->field_label for all form fields.
	 *
	 * @param [int] $form_id Form Id.
	 * @param array $args Extra arguments.
	 * @return array
	 *
	 * @since 2.2.3
	 */
	public function get_form_fields( $form_id, $args = array() ) {
		$hide_fields = array(
			'user_confirm_password',
			'user_confirm_email',
		);

		$fields_dict = array();

		if ( is_numeric( $form_id ) ) {

			$form_data = $this->get_form( $form_id, $args );

			foreach ( $form_data as $sec ) {
				foreach ( $sec as $fields ) {
					foreach ( $fields as $field ) {
						if ( ! isset( $field->general_setting->field_name ) ) {
							continue;
						}

						$field_id    = $field->general_setting->field_name;
						$field_label = $field->general_setting->label;
						if ( ! in_array( $field_id, $hide_fields, true ) ) {
							$fields_dict[ $field_id ] = $field_label;
						}
					}
				}
			}

			if ( isset( $args['hide_fields'] ) && true === $args['hide_fields'] ) {
				foreach ( $hide_fields as $hide_field ) {
					unset( $fields_dict[ $hide_field ] );
				}
			}
		}
		return $fields_dict;
	}

	/**
	 * Get Form data.
	 *
	 * @param int   $id Form ID.
	 * @param array $args Form Arguments.
	 *
	 * @since 1.7.2
	 */
	public function get_form( $id = '', $args = array() ) {
		$forms = array();
		/**
		 * Filter to modify the form args.
		 *
		 * @param array $args The form args.
		 */
		$args = apply_filters( 'user_registration_get_form_args', $args );

		if ( is_numeric( $id ) ) {
			$the_post = get_post( absint( $id ) );

			if ( $the_post && 'user_registration' === $the_post->post_type ) {
				$the_post->post_content = str_replace( '"noopener noreferrer"', "'noopener noreferrer'", $the_post->post_content );
				$the_post->post_content = str_replace( '"noopener"', "'noopener'", $the_post->post_content );

				if ( isset( $args['publish'] ) ) {
					if ( ( $args['publish'] && 'publish' !== $the_post->post_status ) || ( ! $args['publish'] && 'publish' === $the_post->post_status ) ) {
						return array();
					}
				}
				$forms = empty( $args['content_only'] ) ? $the_post : json_decode( $the_post->post_content );
			}
		} else {
			// No ID provided, get multiple forms.
			$defaults = array(
				'post_type'     => 'user_registration',
				'orderby'       => 'ID',
				'order'         => 'DESC',
				'no_found_rows' => true,
				'nopaging'      => true,
			);

			$args = wp_parse_args( $args, $defaults );

			$args['post_type'] = 'user_registration';

			$forms = get_posts( $args );
		}

		return $forms;
	}

	/**
	 * Create new form.
	 *
	 * @since  2.2.4
	 * @param  string $title    Form title.
	 * @param  string $template Form template.
	 * @param  array  $args     Form Arguments.
	 * @param  array  $data     Additional data.
	 * @return int|bool Form ID on successful creation else false.
	 */
	public function create( $title = '', $template = 'blank', $args = array(), $data = array() ) {
		if ( empty( $title ) ) {
			return false;
		}
		/**
		 * Filter to modify the form create args.
		 *
		 * @param array $args The form args.
		 * @param array $data The Additional data.
		 */
		$args = apply_filters( 'user_registration_create_form_args', $args, $data );

		// Prevent content filters from corrupting JSON in post_content.
		$has_kses = ( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
		if ( $has_kses ) {
			kses_remove_filters();
		}
		$has_targeted_link_rel_filters = ( false !== has_filter( 'content_save_pre', 'wp_targeted_link_rel' ) );
		if ( $has_targeted_link_rel_filters ) {
			wp_remove_targeted_link_rel_filters();
		}

		$templates = UR_Admin_Form_Templates::get_template_data();
		$templates = is_array( $templates ) ? $templates : array();

		$form_data = array();

		if ( ! empty( $templates ) && isset( $templates[0]->templates ) ) {
			foreach ( $templates[0]->templates as $template_data ) {
				if ( $template_data->slug === $template && 'blank' !== $template_data->slug ) {
					$form_data                            = json_decode( base64_decode( $template_data->settings ), true );
					$form_data['form_post']['post_title'] = $title;
				}
			}
		}

		// check for non empty post data array.
		$form_data['form_post'] = isset( $form_data['forms'][0]['form_post'] ) ? $form_data['forms'][0]['form_post'] : array();
		$form_data['form_post'] = (object) $form_data['form_post'];

		$form_data = (object) $form_data;

		// If Form Title already exist concat it with imported tag.
		$args  = array( 'post_type' => 'user_registration' );
		$forms = get_posts( $args );
		foreach ( $forms as $key => $form_obj ) {
			if ( isset( $form_data->form_post->post_title ) && ( $form_data->form_post->post_title === $form_obj->post_title ) ) {
				$form_data->form_post->post_title = sanitize_text_field( $title );
				break;
			}
		}

		$form_content = (array) $form_data->form_post;

		if ( empty( $form_content ) ) {
			$post_content         = '[[[{"field_key":"user_login","general_setting":{"label":"Username","description":"","field_name":"user_login","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":"","username_length":"","username_character":"1"},"icon":"ur-icon ur-icon-user"}],[{"field_key":"user_email","general_setting":{"label":"User Email","description":"","field_name":"user_email","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-email"}]],[[{"field_key":"user_pass","general_setting":{"label":"User Password","description":"","field_name":"user_pass","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password"}],[{"field_key":"user_confirm_password","general_setting":{"label":"Confirm Password","description":"","field_name":"user_confirm_password","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password-confirm"}]]]';
			$form_data->form_post = array(
				'post_type'      => 'user_registration',
				'post_title'     => $title,
				'post_content'   => $post_content,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			);
		}

		$form_id = wp_insert_post( $form_data->form_post );

		// Check for any error while inserting.
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}
		if ( $form_id ) {
			add_post_meta( $form_id, 'user_registration_imported_form_template_slug', $template );
			// check for non empty post_meta array.
			if ( ! empty( $form_data->form_post_meta ) ) {
				$form_data->form_post_meta = (object) $form_data->form_post_meta;

				$all_roles = ur_get_default_admin_roles();

				foreach ( $form_data->form_post_meta  as $meta_key => $meta_value ) {

					// If user role does not exists in new site then set default as subscriber.
					if ( 'user_registration_form_setting_default_user_role' === $meta_key ) {
						$meta_value = array_key_exists( $meta_value, $all_roles ) ? $meta_value : 'subscriber';
					}
					add_post_meta( $form_id, $meta_key, $meta_value );
				}
			}
		}

		// Restore removed content filters.
		if ( $has_kses ) {
			kses_init_filters();
		}
		if ( $has_targeted_link_rel_filters ) {
			wp_init_targeted_link_rel_filters();
		}
		/**
		 * Action create form.
		 *
		 * @param int $form_id The form id.
		 * @param array $form_data The form data.
		 * @param array $data The additional data.
		 */
		do_action( 'user_registration_create_form', $form_id, $form_data, $data );

		return $form_id;
	}
}

UR_Form_Handler::init();
