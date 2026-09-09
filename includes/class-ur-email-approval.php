<?php
/**
 * User Registration Email Approval.
 *
 * @class    UR_Email_Approval
 * @since    1.1.5
 * @package  UserRegistration/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UR_Email_Approval
 */
class UR_Email_Approval {

	/**
	 * UR_Email_Approval Constructor.
	 */
	public function __construct() {
		add_action( 'user_registration_after_register_user_action', array( $this, 'set_approval_status' ), 5, 3 );
		add_action( 'user_registration_after_register_user_action', array( $this, 'set_denial_status' ), 5, 3 );
		add_action( 'admin_init', array( __CLASS__, 'approve_user_after_verification' ) );
		add_action( 'admin_init', array( __CLASS__, 'deny_user_after_verification' ) );
	}

	/**
	 * Verify the token and approve the user if the token matches
	 */
	public static function approve_user_after_verification() {
		if ( ! isset( $_GET['ur_approval_token'] ) || empty( $_GET['ur_approval_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		} elseif ( current_user_can( 'edit_users' ) ) {

			$ur_approval_token_raw = sanitize_text_field( wp_unslash( $_GET['ur_approval_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ur_approval_token     = str_split( $ur_approval_token_raw, 50 );
			$token_string          = $ur_approval_token[1];

			if ( 2 < count( $ur_approval_token ) ) {
				unset( $ur_approval_token[0] );
				$token_string = join( '', $ur_approval_token );
			}
			$output  = crypt_the_string( $token_string, 'd' );
			$output  = explode( '_', $output );
			$user_id = absint( $output[0] );
			$form_id = ur_get_form_id_by_userid( $user_id );

				$saved_token = get_user_meta( $user_id, 'ur_confirm_approval_token', true );

			if ( hash_equals( (string) $saved_token, $ur_approval_token_raw ) ) {
				$user_manager = new UR_Admin_User_Manager( $user_id );
				$user_manager->save_status( UR_Admin_User_Manager::APPROVED, true );

				delete_user_meta( $user_id, 'ur_confirm_approval_token' );
				delete_user_meta( $user_id, 'ur_confirm_denial_token' );

				add_action( 'admin_notices', array( __CLASS__, 'approved_success' ) );

				$redirect_url = admin_url() . 'users.php';
				wp_redirect( $redirect_url );
				exit;

			} else {
				add_action( 'admin_notices', array( __CLASS__, 'invalid_approval_token_message' ) );
			}
		} else {
			return;
		}
	}

	/**
	 * Verify the token and deny the user if the token matches
	 */
	public static function deny_user_after_verification() {
		if ( ! isset( $_GET['ur_denial_token'] ) || empty( $_GET['ur_denial_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		} elseif ( current_user_can( 'edit_users' ) ) {

			$ur_denial_token_raw = sanitize_text_field( wp_unslash( $_GET['ur_denial_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ur_denial_token     = str_split( $ur_denial_token_raw, 50 );
			$token_string        = $ur_denial_token[1];

			if ( 2 < count( $ur_denial_token ) ) {
				unset( $ur_denial_token[0] );
				$token_string = join( '', $ur_denial_token );
			}

			$output  = crypt_the_string( $token_string, 'd' );
			$output  = explode( '_', $output );
			$user_id = absint( $output[0] );
			$form_id = ur_get_form_id_by_userid( $user_id );

			$saved_token = get_user_meta( $user_id, 'ur_confirm_denial_token', true );

			if ( hash_equals( (string) $saved_token, $ur_denial_token_raw ) ) {
				$user_manager = new UR_Admin_User_Manager( $user_id );
				$user_manager->save_status( UR_Admin_User_Manager::DENIED, true );

				delete_user_meta( $user_id, 'ur_confirm_denial_token' );
				delete_user_meta( $user_id, 'ur_confirm_approval_token' );

				add_action( 'admin_notices', array( __CLASS__, 'denied_success' ) );

				$redirect_url = admin_url() . 'users.php';
				wp_redirect( $redirect_url );
				exit;

			} else {
				add_action( 'admin_notices', array( __CLASS__, 'invalid_approval_token_message' ) );
			}
		} else {
			return;
		}
	}

	/**
	 * Message to show when user approved successfully
	 */
	public static function approved_success() {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'User approved successfully.', 'user-registration' );
	}

	/**
	 * Message to show when user denied successfully
	 */
	public static function denied_success() {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'User denied successfully.', 'user-registration' );
	}

	/**
	 * Message to show when passed token doesn't match with stored token
	 */
	public static function invalid_approval_token_message() {
		echo "<div class='notice notice-error'><p>" . esc_html__( 'The token is invalid. Please try again.', 'user-registration' ) . '</p></div>';
	}

	/**
	 * Email Approval Disabled Message
	 */
	public static function email_approval_disabled_message() {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Failed to approve user. Email Approval Option is Disabled.', 'user-registration' ) . '</p></div>';
	}

	/**
	 * Email denial Disabled Message
	 */
	public static function email_denial_disabled_message() {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Failed to deny user. Email Approval Option is Disabled.', 'user-registration' ) . '</p></div>';
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
	 * Set the approval token of the user and update it to usermeta table in database.
	 *
	 * @param array $valid_form_data Form filled data.
	 * @param int   $form_id         Form ID.
	 * @param int   $user_id         User ID.
	 */
	public function set_approval_status( $valid_form_data, $form_id, $user_id ) {
		$form_id      = isset( $form_id ) ? $form_id : get_user_meta( $this->user->ID, 'ur_form_id', true );
		$login_option = ur_get_user_login_option( $user_id );

		if ( ( 'admin_approval' == $login_option || 'admin_approval_after_email_confirmation' == $login_option ) ) {
			$token = $this->get_token( $user_id );
			update_user_meta( $user_id, 'ur_confirm_approval_token', $token );
		} else {
			return;
		}
	}

	/**
	 * Set the denial token of the user and update it to usermeta table in database.
	 *
	 * @param array $valid_form_data Form filled data.
	 * @param int   $form_id         Form ID.
	 * @param int   $user_id         User ID.
	 */
	public function set_denial_status( $valid_form_data, $form_id, $user_id ) {
		$form_id      = isset( $form_id ) ? $form_id : get_user_meta( $this->user->ID, 'ur_form_id', true );
		$login_option = ur_get_user_login_option( $user_id );

		if ( ( 'admin_approval' == $login_option || 'admin_approval_after_email_confirmation' == $login_option ) ) {
			$token = $this->get_token( $user_id );
			update_user_meta( $user_id, 'ur_confirm_denial_token', $token );
		} else {
			return;
		}
	}
}

new UR_Email_Approval();
