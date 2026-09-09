<?php
/**
 * User Registration Email Confirmation.
 *
 * @class    UR_Email_Confirmation
 * @since    1.1.5
 * @package  UserRegistration/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UR_Email_Confirmation
 */
class UR_Email_Confirmation {

	/**
	 * UR_Email_Confirmation Constructor.
	 */
	public function __construct() {

		if ( is_admin() ) {
			add_filter( 'manage_users_columns', array( $this, 'add_column_head' ) );
			add_action( 'load-users.php', array( $this, 'trigger_query_actions' ) );
		}

		add_action( 'user_registration_after_register_user_action', array( $this, 'set_email_status' ), 9, 3 );
		add_action( 'template_redirect', array( $this, 'check_token_before_authenticate' ), 30, 2 );
		add_action( 'wp_authenticate', array( $this, 'check_token_before_authenticate' ), 40, 2 );
		add_action( 'template_redirect', array( $this, 'edit_email_confirmation_handler' ) );
	}

	/**
	 * Trigger the action query and check if some users have been verified or unverified
	 */
	public function trigger_query_actions() {

		$resend_verification_sent = isset( $_REQUEST['resend_verification_sent'] ) ? sanitize_key( $_REQUEST['resend_verification_sent'] ) : false;
		if ( $resend_verification_sent ) {
			add_action( 'admin_notices', array( $this, 'ur_admin_notice_resend_verification_sent' ) );
		}

		$user_id = absint( isset( $_GET['user'] ) ? $_GET['user'] : 0 );

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : false;
		$mode   = isset( $_POST['mode'] ) ? wp_unslash( sanitize_key( $_POST['mode'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification

		// If this is a multisite, bulk request, stop now!
		if ( 'list' == $mode ) {
			return;
		}

		if ( ! empty( $action ) && in_array( $action, array( 'resend_verification' ) ) && ! isset( $_GET['new_role'] ) ) {

			check_admin_referer( 'ur_user_change_email_status' );

			$redirect = admin_url( 'users.php' );
			$status   = $action;

			if ( 'resend_verification' == $status ) {
				ur_resend_verification_email( $user_id );
				$redirect = add_query_arg( array( 'resend_verification_sent' => 1 ), $redirect );
			}
			/**
			 * Filter to modify the admin action redirect.
			 *
			 * @param array $redirect The admin redirect.
			 */
			wp_safe_redirect( esc_url_raw( apply_filters( 'user_registration_admin_action_redirect', $redirect ) ) );
			exit;
		}
	}

	/**
	 * Admin notice after resend verification email sent.
	 *
	 * @since 1.9.4
	 */
	public function ur_admin_notice_resend_verification_sent() {
		echo '<div class="notice-success notice is-dismissible"><p>' . esc_html__( 'Verification Email Sent Successfully !! ', 'user-registration' ) . '</p></div>';
	}

	/**
	 * Add the column header for the email status column
	 *
	 * @param array $columns Column.
	 *
	 * @return array
	 */
	public function add_column_head( $columns ) {
		if ( ! current_user_can( 'edit_user' ) ) {
			return $columns;
		}

		$the_columns['ur_user_user_status'] = esc_html__( 'Status', 'user-registration' );

		$newcol  = array_slice( $columns, 0, -1 );
		$newcol  = array_merge( $newcol, $the_columns );
		$columns = array_merge( $newcol, array_slice( $columns, 1 ) );

		return $columns;
	}

	/**
	 * Enqueque CSS to load notice
	 *
	 * @return void
	 */
	public function ur_enqueue_script() {
		wp_register_style( 'user-registration-css', UR()->plugin_url() . '/assets/css/user-registration.css', array(), UR_VERSION );
		wp_enqueue_style( 'user-registration-css' );
	}

	/**
	 * Successful registration message.
	 */
	public function custom_registration_message() {
		$default = __( 'User successfully registered. Login to continue.', 'user-registration' );
		$message = get_option( 'user_registration_successful_email_verified_message', $default );
		$message = ur_string_translation( 0, 'user_registration_successful_email_verified_message', $message );
		return ur_print_notice( $message );
	}

	/**
	 * Email Successfully verified and waiting for admin approval Message.
	 */
	public function custom_email_confirmed_admin_await_message() {
		$default = __( 'Email has successfully been verified. Now, please wait until the admin approves you to give access for the login.', 'user-registration' );
		$message = get_option( 'user_registration_pro_email_verified_admin_approval_await_message', $default );
		$message = ur_string_translation( 0, 'user_registration_pro_email_verified_admin_approval_await_message', $message );
		return ur_print_notice( $message );
	}

	/**
	 * Token mismatch message.
	 */
	public function custom_registration_error_message() {
		return ur_print_notice( esc_html__( 'Token Mismatch!', 'user-registration' ), 'error' );
	}

	/**
	 * Token expired message.
	 */
	public function custom_token_expired_message() {
		return ur_print_notice( esc_html__( 'Token Expired . Please request for new verification email.', 'user-registration' ), 'error' );
	}

	/**
	 * Resend verification email message.
	 */
	public function custom_resend_email_token_message() {
		return ur_print_notice( esc_html__( 'Verification Email Sent!', 'user-registration' ) );
	}

	/**
	 * Resend verification email error message.
	 */
	public function custom_resend_email_token_error_message() {
		return ur_print_notice( esc_html__( 'User does not exist!', 'user-registration' ), 'error' );
	}

	/**
	 * Compare user token with token in url
	 *
	 * @return void
	 */
	public function check_token_before_authenticate() {

		$user_reg_successful = false;

		add_action( 'login_enqueue_scripts', array( $this, 'ur_enqueue_script' ), 1 );

		// Condition for resending token.
		if (isset($_GET['ur_resend_id']) && isset($_GET['ur_resend_token']) && ur_string_to_bool($_GET['ur_resend_token'])) { //phpcs:ignore;
			if ( empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( sanitize_key( $_REQUEST['_wpnonce'] ) ), 'ur_resend_token' ) ) {
				die( esc_html__( 'Action failed. Please refresh the page and retry.', 'user-registration' ) );
			}

			$output  = crypt_the_string( sanitize_text_field( wp_unslash( $_GET['ur_resend_id'] ) ), 'd' );
			$output  = explode( '_', $output );
			$user_id = absint( $output[0] );
			$user    = get_user_by( 'id', $user_id );

			$form_id = ur_get_form_id_by_userid( $user_id );

			$login_option = ur_get_user_login_option( $user_id );

			if ( $user && ( 'email_confirmation' === $login_option || 'admin_approval_after_email_confirmation' === $login_option ) ) {
				$this->set_email_status( array(), '', $user_id );

				/**
				 * Filter hook to modify the email attachment resending token.
				 * Default value is empty array.
				 */
				$attachments = apply_filters( 'user_registration_email_attachment_resending_token', array() );
				$name_value  = ur_get_user_extra_fields( $user_id );
				// Get selected email template id for specific form.
				$template_id = ur_get_single_post_meta( $form_id, 'user_registration_select_email_template' );

				UR_Emailer::send_mail_to_user( $user->user_email, $user->user_login, $user_id, '', $name_value, $attachments, $template_id );

				add_filter( 'login_message', array( $this, 'custom_resend_email_token_message' ) );
				add_filter( 'user_registration_login_form_before_notice', array( $this, 'custom_resend_email_token_message' ) );
			} else {

				add_filter( 'login_message', array( $this, 'custom_resend_email_token_error_message' ) );
				add_filter( 'user_registration_login_form_before_notice', array( $this, 'custom_resend_email_token_error_message' ) );
			}
		}

		if ( ! isset( $_GET['ur_token'] ) || empty( $_GET['ur_token'] ) ) {
			return;
		} else {
			$ur_token_raw = sanitize_text_field( wp_unslash( $_GET['ur_token'] ) );
			$ur_token     = str_split( $ur_token_raw, 50 );

			// A token of 50 characters or fewer has no second chunk, so there is nothing to decrypt.
			if ( count( $ur_token ) < 2 ) {
				return;
			}

			$token_string = $ur_token[1];

			if ( 2 < count( $ur_token ) ) {
				unset( $ur_token[0] );
				$token_string = join( '', $ur_token );
			}
			$output     = crypt_the_string( $token_string, 'd' );
			$output     = explode( '_', $output );
			$user_id    = absint( $output[0] );
			$user_token = get_user_meta( $user_id, 'ur_confirm_email_token', true );

			if ( empty( $user_token ) ) {
				return;
			}

			$form_id = ur_get_form_id_by_userid( $user_id );

			// Check if the token matches the token value stored in db.
			$login_option = ur_get_user_login_option( $user_id );

			if ( hash_equals( (string) $user_token, $ur_token_raw ) && ( 'email_confirmation' === $login_option || 'admin_approval_after_email_confirmation' === $login_option ) ) {
				$token_expiration_duration = 24 * 60 * 60;
				/**
				 * Filter hook to modify the token expiration duration.
				 * Default email confirmation token expiration duration is 24 hour.
				 */
				$token_expiration_duration = apply_filters( 'user_registration_email_confirmation_token_expiration_duration', $token_expiration_duration );

				if ( isset( $output[1] ) && time() > ( $output[1] + $token_expiration_duration ) ) {
					add_filter( 'login_message', array( $this, 'custom_token_expired_message' ) );
					add_filter( 'user_registration_login_form_before_notice', array( $this, 'custom_token_expired_message' ) );
				} else {
					$user_reg_successful = true;

					update_user_meta( $user_id, 'ur_confirm_email', 1 );
					delete_user_meta( $user_id, 'ur_confirm_email_token' );

					$user = get_user_by( 'id', $user_id );
					/**
					 * Filter hook to modify the email attachment resending token.
					 * Default value is empty array.
					 */
					$attachments = apply_filters( 'user_registration_email_attachment_resending_token', array() );
					$name_value  = ur_get_user_extra_fields( $user_id );
					// Get selected email template id for specific form.
					$template_id = ur_get_single_post_meta( $form_id, 'user_registration_select_email_template' );

					UR_Emailer::send_mail_to_user( $user->user_email, $user->user_login, $user_id, '', $name_value, $attachments, $template_id );

					if ( 'admin_approval_after_email_confirmation' === $login_option ) {
						add_filter( 'login_message', array( $this, 'custom_email_confirmed_admin_await_message' ) );
						add_filter( 'user_registration_login_form_before_notice', array( $this, 'custom_email_confirmed_admin_await_message' ) );
					} else {
						$allow_automatic_user_login = apply_filters( 'user_registration_allow_automatic_user_login_email_confirmation', true );

						// Sets the toast container and its value in the cookie.
						$toast_success_message = esc_html__( 'Your email has been successfully verified.', 'user-registration' );
						$toast_success_message = apply_filters( 'user_registration_approval_confirmation_message', $toast_success_message );
						$toast_content         = '<div class="user-registration-membership-notice__container">
									<div class="ur-toaster user-registration-membership-notice__red">
										<span class="user-registration-membership-notice__message"></span>
										<span class="user-registration-membership__close_notice">&times;</span>
									</div>
								</div>';

						setcookie( 'urm_toast_content', $toast_content, time() + 5, '/', '', is_ssl(), true );
						setcookie( 'urm_toast_success_message', $toast_success_message, time() + 5, '/', '', is_ssl(), true );

						add_filter( 'login_message', array( $this, 'custom_registration_message' ) );
						add_filter( 'user_registration_login_form_before_notice', array( $this, 'custom_registration_message' ) );
						if ( $allow_automatic_user_login ) {
							/**
							 * Action hook to check the token complete.
							 *
							 * @param array $user_id The user ID.
							 * @param bool $user_reg_successful The user registration successful.
							 */
							do_action( 'user_registration_check_token_complete', $user_id, $user_reg_successful );
							ur_automatic_user_login( $user );
						}
					}
				}
			} else {
				add_filter( 'login_message', array( $this, 'custom_registration_error_message' ) );
				add_filter( 'user_registration_login_form_before_notice', array( $this, 'custom_registration_error_message' ) );
			}
			/**
			 * Action hook to check the token complete.
			 *
			 * @param array $user_id The user ID.
			 * @param bool $user_reg_successful The user registration successful.
			 */
			do_action( 'user_registration_check_token_complete', $user_id, $user_reg_successful );
		}
	}

	/**
	 * Handler for edit confirmation email.
	 *
	 * @return void
	 */
	public function edit_email_confirmation_handler() {
		global $wp;

		if ( ! isset( $_GET['confirm_email'] ) || ! isset( $_GET['confirm_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		// Verify the confirmation key.
		$user_id     = absint( wp_unslash( $_GET['confirm_email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$confirm_key = sanitize_text_field( wp_unslash( $_GET['confirm_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$stored_key  = get_user_meta( $user_id, 'user_registration_email_confirm_key', true );
		$expiration  = get_user_meta( $user_id, 'user_registration_pending_email_expiration', true );

		if ( time() > $expiration || $confirm_key !== $stored_key ) {
			return;
		}
		/**
		 * Trigger an action hook before the email address is update.
		 *
		 * @param int $user_id The user ID.
		 */
		do_action( 'user_registration_before_email_change_update', $user_id );

		// Update the user's email address to the new one.
		wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => get_user_meta( $user_id, 'user_registration_pending_email', true ),
			)
		);
		/**
		 * Trigger an action hook after the email address is updated.
		 *
		 * @param int $user_id The user ID.
		 */
		do_action( 'user_registration_email_change_success', $user_id );

		// Remove the confirmation key, pending email and expiry date.
		UR_Form_Handler::delete_pending_email_change( $user_id );

		wp_safe_redirect( home_url( add_query_arg( array(), $wp->request ) ) );
		exit;
	}

	/**
	 * Generate email token for the user.
	 *
	 * @param  int $user_id User ID.
	 * @return string   Token.
	 */
	public function get_token( $user_id ) {

		$length         = 50;
		$token          = '';
		$code_alphabet  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$code_alphabet .= 'abcdefghijklmnopqrstuvwxyz';
		$code_alphabet .= '0123456789';
		$max            = strlen( $code_alphabet );

		for ( $i = 0; $i < $length; $i++ ) {
			$token .= $code_alphabet[ random_int( 0, $max - 1 ) ];
		}

		$token .= crypt_the_string( $user_id . '_' . time(), 'e' );

		return $token;

		do_action( 'user_registration_get_token' );
	}

	/**
	 * Set the token of the user and update it to usermeta table in database.
	 *
	 * @param array $valid_form_data Form filled data.
	 * @param int   $form_id         Form ID.
	 * @param int   $user_id         User ID.
	 */
	public function set_email_status( $valid_form_data, $form_id, $user_id ) {
		$form_id      = isset( $form_id ) ? $form_id : 0;
		$login_option = ur_get_user_login_option( $user_id );

		if ( 'email_confirmation' === $login_option || 'admin_approval_after_email_confirmation' === $login_option ) {
			$token = $this->get_token( $user_id );
			update_user_meta( $user_id, 'ur_confirm_email', 0 );
			update_user_meta( $user_id, 'ur_confirm_email_token', $token );

			if ( 'admin_approval_after_email_confirmation' === $login_option ) {
				update_user_meta( $user_id, 'ur_admin_approval_after_email_confirmation', 'false' );
				update_user_meta( $user_id, 'ur_user_status', 0 );
			}

			// update user status when login using social connect.
			$is_social_login_option_enabled = ur_option_checked( 'user_registration_social_setting_enable_login_options', false );

			if ( ! $is_social_login_option_enabled && get_user_meta( $user_id, 'user_registration_social_connect_bypass_current_password', false ) ) {
				update_user_meta( $user_id, 'ur_confirm_email', 1 );

				if ( 'admin_approval_after_email_confirmation' === $login_option ) {
					update_user_meta( $user_id, 'ur_admin_approval_after_email_confirmation', 'true' );
					update_user_meta( $user_id, 'ur_user_status', 0 );
				}
			}
		}
	}

	/**
	 * Check the email status during authentication
	 *
	 * @param  WP_User $user User instance.
	 * @param mixed   $password Password.
	 * @return mixed
	 */
	public function check_email_status( WP_User $user, $password ) {
		$form_id = ur_get_form_id_by_userid( $user->ID );

		$general_login_option = get_option( 'user_registration_general_setting_login_options', 'default' );

		if ( 'email_confirmation' === ur_get_user_login_option( $user->ID ) ) {
			$email_status = get_user_meta( $user->ID, 'ur_confirm_email', true );
			/**
			 * Action before check email status on login
			 *
			 * @param bool $email_status The email status.
			 * @param array $user The user data.
			 */
			do_action( 'ur_user_before_check_email_status_on_login', $email_status, $user );

			$website = isset( $_SERVER['SERVER_NAME'] ) && isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'] : '';   //phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			$url = ( ! empty( $_SERVER['HTTPS'] ) ) ? 'https://' . $website : 'http://' . $website;
			$url = substr( $url, 0, strpos( $url, '?' ) );
			$url = wp_nonce_url( $url . '?ur_resend_id=' . crypt_the_string( $user->ID . '_' . time(), 'e' ) . '&ur_resend_token=true', 'ur_resend_token' );

			if ( '0' === $email_status ) {
				/* translators: %s - Resend Verification Link. */
				$message = '<strong>' . __( 'ERROR:', 'user-registration' ) . '</strong> ' . sprintf( __( 'Your account is still pending approval. Verify your email by clicking on the link sent to your email. %s', 'user-registration' ), '<a id="resend-email" href="' . esc_url( $url ) . '">' . __( 'Resend Verification Link', 'user-registration' ) . '</a>' );
				return new WP_Error( 'user_email_not_verified', $message );
			}
			return $user;
		}
		return $user;
	}

	/**
	 * Deprecated my_simple_crypt function.
	 *
	 * @deprecated 1.4.0
	 * @param  string $string the string to encrypt/decrypt.
	 * @param  string $action the action encrypt or decrypt.
	 * @return void
	 */
	public function my_simple_crypt( $string, $action ) {
		ur_deprecated_function( 'UR_Email_Confirmation::my_simple_crypt', '1.4.0', 'crypt_the_string' );
	}

	/**
	 * Deprecated getToken function.
	 *
	 * @deprecated 1.4.0
	 * @param int $user_id User's ID.
	 * @return void
	 */
	public function getToken( $user_id ) {
		ur_deprecated_function( 'UR_Email_Confirmation::getToken', '1.4.0', 'UR_Email_Confirmation::get_token' );
	}
}

new UR_Email_Confirmation();
