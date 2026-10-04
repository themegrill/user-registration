<?php
/**
 * User Registration User Approval.
 *
 * @class    UR_User_Approval
 * @version  1.0.0
 * @package  UserRegistration/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UR_User_Approval
 */
class UR_User_Approval {

	/**
	 * UR_User_Approval constructor.
	 */
	public function __construct() {

		// -------------------- ACTIONS & FILTERS --------------------
		// Additional checks
		add_action( 'after_setup_theme', array( $this, 'check_status_on_page' ) );

		// Handle user Sign in.
		add_action( 'user_registration_after_register_user_action', array( $this, 'set_user_status' ), 10, 3 );
		add_action( 'user_register', array( $this, 'send_request_notification_to_admin' ), 10, 1 );
		add_filter( 'wp_login_errors', array( $this, 'registration_completed_message' ) );

		// Handle user Sign on.
		add_action( 'wp_login', array( $this, 'track_first_login' ), 10, 2 );
		add_filter( 'wp_authenticate_user', array( $this, 'check_status_on_login' ), 10, 2 );

		// Handle Lost Password Page.
		add_filter( 'allow_password_reset', array( $this, 'allow_password_reset' ), 10, 2 );

		// When the approval status of an user change.
		add_action(
			'ur_user_status_updated',
			array(
				$this,
				'send_notification_to_user_about_status_changing',
			),
			10,
			3
		);
		add_action( 'ur_user_user_denied', array( $this, 'disconnect_user_session' ) );
		/**
		 * Executes an action when constructing a user.
		 *
		 * The 'ur_user_construct' action is triggered during the construction of a user.
		 */
		do_action( 'ur_user_construct' );
	}

	/**
	 * Display a message the provide instruction after the use regsitration and remove the login form from there
	 *
	 * @param array $errors Errors.
	 *
	 * @return mixed
	 */
	public function registration_completed_message( $errors ) {

		if ( ! ( isset( $_GET['checkemail'] ) && 'registered' === $_GET['checkemail'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $errors;
		}

		return '';
	}

	/**
	 * Save a flag that ensure if an user has ever loggedin while the plugin is activated
	 *
	 * @param mixed $user_login Username.
	 * @param mixed $user Users Object.
	 */
	public function track_first_login( $user_login, $user ) {

		$form_id = ur_get_form_id_by_userid( $user->ID );

		if ( 'admin_approval' === ur_get_user_login_option( $user->ID ) ) {
			$user_manager = new UR_Admin_User_Manager( $user );
			$user_manager->save_first_access_flag();
		}
	}

	/**
	 * Send the email to the user that alert if the approvalrequest has been approved or rejected.
	 * If the request is approved and the user needs to receive the password, a new password will be generated and sent
	 *
	 * @param mixed $status Status.
	 * @param int   $user_id User ID.
	 * @param mixed $alert_user Alert User.
	 */
	public function send_notification_to_user_about_status_changing( $status, $user_id, $alert_user ) {

		$form_id = ur_get_form_id_by_userid( $user_id );

		if ( ! $alert_user && 'admin_approval' !== ur_get_user_login_option( $user_id ) ) {
			return;
		}

		$user_manager = new UR_Admin_User_Manager( $user_id );

		$user_status = $user_manager->get_user_status();

		// Avoid to send multiple times the same email.
		if ( $status === $user_status['user_status'] ) {
			return;
		}

		$user      = get_userdata( $user_id );
		$user_data = isset( $user->data ) ? $user->data : array();
		$username  = isset( $user_data->user_login ) ? $user_data->user_login : '';
		$email     = isset( $user_data->user_email ) ? $user_data->user_email : '';

		UR_Emailer::status_change_email( $email, $username, $status, $form_id );
	}

	/**
	 * Send an email to the admin in order to alert the a new user requests to be approved
	 *
	 * @param int $user_id User ID.
	 */
	public function send_request_notification_to_admin( $user_id ) {

		// If the user is created by admin or if the admin alert is disabled, doesn't send the email to the admin.
		if ( $this->is_admin_creation_process() ) {
			return;
		}
	}

	/**
	 * Set the status of the user right after the registration.
	 *
	 * @param mixed $form_data Form Data.
	 * @param int   $form_id Form ID.
	 * @param int   $user_id User ID.
	 */
	public function set_user_status( $form_data, $form_id, $user_id ) {

		if ( 'admin_approval' === ur_get_user_login_option( $user_id ) ) {

			$status = UR_Admin_User_Manager::PENDING;
			// If the user is created by admin in the backend, than automatically approve him.
			if ( $this->is_admin_creation_process() ) {
				$status = UR_Admin_User_Manager::APPROVED;
			}
			// update user status when login using social connect.
			$is_social_login_option_enabled = ur_option_checked( 'user_registration_social_setting_enable_login_options', false );

			if ( ! $is_social_login_option_enabled && get_user_meta( $user_id, 'user_registration_social_connect_bypass_current_password', false ) ) {
				$status = UR_Admin_User_Manager::APPROVED;
			}

			$user_manager = new UR_Admin_User_Manager( $user_id );

			// The user have to be not alerted on status creation, it will be always pending or approved.
			$alert_user = false;

			$user_manager->save_status( $status, $alert_user );
		}
	}

	/**
	 * Check the status of an user on login.
	 *
	 * @param mixed  $user Users.
	 * @param string $password Password.
	 *
	 * @return \WP_Error
	 */
	public function check_status_on_login( $user, $password ) {

		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		$form_id = ur_get_form_id_by_userid( $user->ID );

		$login_option = ur_get_user_login_option( $user->ID );

		$user_manager = new UR_Admin_User_Manager( $user );

		$status = $user_manager->get_user_status();

		$membership           = array();
		$is_membership_active = ur_check_module_activation( 'membership' );
		$is_disabled          = get_user_meta( $user->ID, 'ur_disable_users', true );

		if ( $is_disabled ) {
			$message = '<strong>' . __( 'ERROR:', 'user-registration' ) . '</strong> ' . apply_filters( 'user_registration_user_disabled_message', __( 'Sorry! You are disabled. Please Contact Your Administrator.', 'user-registration' ) );

			return new WP_Error( 'disable_user', $message );
		} elseif ( ( 'admin_approval' === $login_option || 'admin_approval' === $status['login_option'] ) ) {
			/**
			 * Executes an action before checking the user status on user login.
			 *
			 * The 'ur_user_before_check_status_on_login' action allows developers to perform
			 * actions before the user status is checked during user login.
			 *
			 * @param string $user_status Default user status.
			 * @param WP_User $user The user object.
			 */
			do_action( 'ur_user_before_check_status_on_login', $status['user_status'], $user );

			switch ( $status['user_status'] ) {
				case UR_Admin_User_Manager::APPROVED:
					return $user;
					break;
				case UR_Admin_User_Manager::PENDING:
					$message = '<strong>' . __( 'ERROR:', 'user-registration' ) . '</strong> ' . __( 'Your account is still pending approval.', 'user-registration' );

					return new WP_Error( 'pending_approval', $message );
					break;
				case UR_Admin_User_Manager::DENIED:
					$message = '<strong>' . __( 'ERROR:', 'user-registration' ) . '</strong> ' . __( 'Your account has been denied.', 'user-registration' );

					return new WP_Error( 'denied_access', $message );
					break;
			}
		} elseif ( ( 'admin_approval_after_email_confirmation' === $login_option || 'admin_approval_after_email_confirmation' === $status['login_option'] ) ) {
			/**
			 * Executes an action before checking the user status on user login.
			 *
			 * The 'ur_user_before_check_status_on_login' action allows developers to perform
			 * actions before the user status is checked during user login.
			 *
			 * @param string $user_status Default user status.
			 * @param WP_User $user The user object.
			 */
			do_action( 'ur_user_before_check_status_on_login', $status['user_status'], $user );

			switch ( $status['user_status'] ) {
				case UR_Admin_User_Manager::APPROVED:
					return $user;
					break;
				case UR_Admin_User_Manager::PENDING:
					$user_email_status = get_user_meta( $user->ID, 'ur_confirm_email', true );
					if ( ur_string_to_bool( $user_email_status ) ) {
						$message = '<strong>' . __( 'ERROR:', 'user-registration' ) . '</strong> ' . __( 'Your account is still pending approval.', 'user-registration' );

						return new WP_Error( 'pending_approval', $message );
					} else {
						$url = ( ! empty( $_SERVER['HTTPS'] ) ) ? 'https://' . $_SERVER['SERVER_NAME'] : 'http://' . $_SERVER['SERVER_NAME']; //phpcs:ignore

						if ( get_option( 'ur_login_ajax_submission' ) ) {
							$url .= $_SERVER['HTTP_REFERER']; //phpcs:ignore
						} else {
							$url .= $_SERVER['REQUEST_URI']; //phpcs:ignore
						}
						$url = substr( $url, 0, strpos( $url, '?' ) );
						$url = wp_nonce_url( $url . '?ur_resend_id=' . crypt_the_string( $user->ID . '_' . time(), 'e' ) . '&ur_resend_token=true', 'ur_resend_token' );
						/* translators: %s - Resend Verification Link. */
						$message = '<strong>' . esc_html__( 'ERROR:', 'user-registration' ) . '</strong> ' . sprintf( __( 'Your account is still pending approval. Verify your email by clicking on the link sent to your email. %s', 'user-registration' ), '<a id="resend-email" href="' . esc_url( $url ) . '">' . __( 'Resend Verification Link', 'user-registration' ) . '</a>' );

						return new WP_Error( 'user_email_not_verified', $message );
					}
					break;
				case UR_Admin_User_Manager::DENIED:
					$message = '<strong>' . __( 'ERROR:', 'user-registration' ) . '</strong> ' . __( 'Your account has been denied.', 'user-registration' );

					return new WP_Error( 'denied_access', $message );
					break;
			}
		} elseif ( 'email_confirmation' === $login_option || 'email_confirmation' === $status['login_option'] ) {
			/**
			 * Executes an action before checking the email status on user login.
			 *
			 * The 'ur_user_before_check_email_status_on_login' action allows developers to perform
			 * actions before the user status is checked during user login.
			 *
			 * @param string $user_status Default user status.
			 * @param WP_User $user The user object.
			 */
			do_action( 'ur_user_before_check_email_status_on_login', $status['user_status'], $user );

			$url = ( ! empty( $_SERVER['HTTPS'] ) ) ? 'https://' . $_SERVER['SERVER_NAME'] : 'http://' . $_SERVER['SERVER_NAME']; //phpcs:ignore

			if ( get_option( 'ur_login_ajax_submission' ) ) {
				$url .= isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : ""; //phpcs:ignore
				$url .= $_SERVER['HTTP_REFERER']; //phpcs:ignore
			} else {
				$url .= isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ""; //phpcs:ignore
			}

			$url = substr( $url, 0, strpos( $url, '?' ) );
			$url = wp_nonce_url( $url . '?ur_resend_id=' . crypt_the_string( $user->ID . '_' . time(), 'e' ) . '&ur_resend_token=true', 'ur_resend_token' );
			// if login option is email_confirmation but admin denies user.

			if ( UR_Admin_User_Manager::DENIED === (int) $status['user_status'] ) {
				$message = '<strong>' . __( 'ERROR:', 'user-registration' ) . '</strong> ' . __( 'Your account has been denied.', 'user-registration' );

				return new WP_Error( 'denied_access', $message );
			}

			if ( '0' === $status['user_status'] ) {
				/* translators: %s - Resend Verification Link. */
				$message = '<strong>' . esc_html__( 'ERROR:', 'user-registration' ) . '</strong> ' . sprintf( __( 'Your account is still pending approval. Verify your email by clicking on the link sent to your email. %s', 'user-registration' ), '<a id="resend-email" href="' . esc_url( $url ) . '">' . __( 'Resend Verification Link', 'user-registration' ) . '</a>' );

				return new WP_Error( 'user_email_not_verified', $message );
			}

			return $user;
		} elseif ( 'payment' === $login_option ) {

			if ( $is_membership_active ) {
				$members_repository       = new \WPEverest\URMembership\Admin\Repositories\MembersRepository();
				$membership               = $members_repository->get_member_membership_by_id( $user->ID );
				$members_order_repository = new \WPEverest\URMembership\Admin\Repositories\MembersOrderRepository();
				$last_order               = $members_order_repository->get_member_orders( $user->ID );
			}

			$payment_status   = get_user_meta( $user->ID, 'ur_payment_status', true );
			$requires_payment = 'yes' === get_user_meta( $user->ID, 'ur_requires_payment', true );
			$is_member        = $is_membership_active && ! empty( $membership ) && ! empty( $last_order );
			if ( $is_member ) {
				$payment_status            = $last_order['status'];
				$membership_payment_method = $last_order['payment_method'];
				$membership_id             = $last_order['item_id'];
			}

			/**
			 * Executes an action before checking the payment status on user login.
			 *
			 * @param string $payment_status Default payment status.
			 * @param WP_User $user The user object.
			 */
			do_action( 'ur_user_before_check_payment_status_on_login', $payment_status, $user );

			if ( 'completed' !== $payment_status && ( $requires_payment || ! empty( $payment_status ) ) ) {
				$message = '<strong>' . __( 'ERROR:', 'user-registration' ) . '</strong> ' . __( 'Your account is still pending payment.', 'user-registration' );

				$payment_method = $is_member ? $membership_payment_method : get_user_meta( $user->ID, 'ur_payment_method', true );

				if ( 'paypal_standard' === $payment_method || 'paypal' === $payment_method || 'mollie' === $payment_method ) {

					$user_id      = $user->ID;
					$redirect_url = paypal_generate_redirect_url( $user_id );

					if ( $is_member && ! empty( $membership_id ) ) {
						$payment_service = new \WPEverest\URMembership\Admin\Services\PaymentService( $payment_method, $membership_id, $user->user_email );
						$response_data   = array(
							'membership'      => $membership_id,
							'subscription_id' => $membership[0]['subscription_id'],
							'member_id'       => $user_id,
						);
						$is_upgrading    = get_user_meta( $user_id, 'urm_is_user_upgraded', true );
						if ( $is_upgrading ) {
							$next_sub_data = json_decode( get_user_meta( $user_id, 'urm_next_subscription_data', true ), true );
							$response_data = $next_sub_data;
						}
						$response     = $payment_service->build_response( $response_data );
						$redirect_url = $response['payment_url'];
					}

					/* translators: %s - Redirect URL. */
					$message = '<strong>' . __( 'ERROR:', 'user-registration' ) . '</strong> ' . str_replace( '%s', esc_url( $redirect_url ), get_option( 'user_registration_pro_pending_payment_error_message', __( 'Your account is still pending payment. Process the payment by clicking on this: <a id="payment-link" href="%s">link</a>', 'user-registration' ) ) );
				}
				/**
				 * Applies a filter before checking the payment status on user login.
				 *
				 * @param string $message Default Message.
				 * @param WP_User $user The user object.
				 */
				$message = apply_filters( 'ur_user_before_check_payment_status_on_login', $message, $user );

				return new WP_Error( 'user_payment_pending', $message );
			}

			return $user;
		}

		return $user;
	}

	public function check_user_membership( $membership, $user, $last_order, $login_option ) {
		$membership_status = count( $membership ) < 2 && ! empty( $membership ) ? $membership[0]['status'] : 'active';

		switch ( $membership_status ) {
			case 'pending':
				if ( ( $last_order['payment_method'] === 'paypal' || $last_order['payment_method'] === 'mollie' ) && $last_order['status'] === 'pending' && 'payment' === $login_option ) {
					break;
				}
				$message = '<strong>' . __( 'ERROR:', 'user-registration' ) . '</strong> ' . __( 'Your subscription is not active. Please contact administrator.', 'user-registration' );

				return new WP_Error( 'denied_access', $message );
				break;
			default:
				return $user;
				break;
		}
	}

	public function check_membership_payment_status( $user ) {
	}

	/**
	 * Check on every page if the current user is actual approved, otherwise logout him
	 * This is an additional protection against that themes or plugins that login users automatically after sign up
	 */
	public function check_status_on_page() {

		if ( ! is_user_logged_in() ) {
			return;
		}

		$form_id = ur_get_form_id_by_userid( get_current_user_id() );

		if ( 'admin_approval' === ur_get_user_login_option( get_current_user_id() ) && $form_id ) {

			// Try to hide the not approved users from any theme or plugin request in frontend.
			$disable_pre_get = apply_filters( 'user_registration_disable_pre_get_users', 'no' );

			if ( 'no' === $disable_pre_get ) {
				add_action( 'pre_get_users', array( $this, 'hide_not_approved_users_in_frontend' ) );
			}

			$status = ur_get_user_approval_status( get_current_user_id() );

			$user_manager = new UR_Admin_User_Manager();

			if ( ! $user_manager->can_status_be_changed_by( get_current_user_id() ) ) {
				return;
			}
			/**
			 * Executes an action before checking the user status on a page.
			 *
			 * The 'ur_user_before_check_status_on_page' action allows developers to perform
			 * actions before the user status is checked on a page.
			 *
			 * @param array $status User status information.
			 * @param UR_User_Manager $user_manager The User Manager instance.
			 */
			do_action( 'ur_user_before_check_status_on_page', $status, $user_manager );

			if ( UR_Admin_User_Manager::APPROVED === $status ) {
				return;
			}

			wp_logout();
		}
	}

	/**
	 * Check the $_REQUEST variable to understand if the user currently created is created by admin in the backend or noth
	 *
	 * @return bool
	 */
	protected function is_admin_creation_process() {
		return (
			is_admin() &&
			current_user_can( 'create_users' ) &&
			isset( $_REQUEST['action'] ) && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'createuser' === $_REQUEST['action'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * Disconnect an user selected by id.
	 *
	 * @param int $user_id User Id.
	 */
	public function disconnect_user_session( $user_id ) {

		if ( 'admin_approval' === ur_get_user_login_option( $user_id ) ) {
			// get all sessions for user with ID $user_id.
			$sessions = WP_Session_Tokens::get_instance( $user_id );

			// we have got the sessions, destroy them all!
			$sessions->destroy_all();
		}
	}

	/**
	 * If the user is not approved, disalow to reset the password fom Lost Passwod form and display an error message
	 *
	 * @param mixed $result Result.
	 * @param int   $user_id User ID.
	 *
	 * @return \WP_Error
	 */
	public function allow_password_reset( $result, $user_id ) {

		$user_manager = new UR_Admin_User_Manager( $user_id );

		if ( ! $user_manager->is_approved() ) {
			$error_message = __( 'Your account is still pending approval. Reset Password is not allowed.', 'user-registration' );
			$result        = new WP_Error( 'user_not_approved', $error_message );
		}

		return $result;
	}

	/**
	 * Function called on action pre_get_users, it remove all users not approved when the request is don by frontend,
	 * in this way it ensure a compatibility with all other plugin and themes, avoiding to show unapproved users
	 * (for instance in members page of buddypress or Extrafooter of Woffice)
	 *
	 * @param \WP_Query $query Query.
	 */
	public function hide_not_approved_users_in_frontend( $query ) {

		// If this is not a frontend page, then do nothing.
		if ( is_admin() ) {
			return;
		}

		if ( isset( $query->query_vars['ur_user_ignore_users_hiding'] ) && $query->query_vars['ur_user_ignore_users_hiding'] ) {
			return;
		}

		// Otherwise display only approved users.
		$meta_query = array(
			'relation' => 'OR',
			array(
				'key'     => 'ur_user_status',
				'compare' => 'NOT EXISTS', // works!
				'value'   => '', // This is ignored, but is necessary...
			),
			array(
				'key'   => 'ur_user_status',
				'value' => UR_Admin_User_Manager::APPROVED,
			),
		);
		/**
		 * Applies a filter to modify the meta query for hiding not approved users in the frontend.
		 *
		 * The 'ur_user_hide_not_approved_users_in_frontend' filter allows developers to modify
		 * the meta query used for hiding not approved users in the frontend.
		 *
		 * @param array $meta_query Default meta query.
		 * @param WP_Query $query The WP_Query object.
		 */
		$meta_query = apply_filters( 'ur_user_hide_not_approved_users_in_frontend', $meta_query, $query );

		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}
	}
}

new UR_User_Approval();
