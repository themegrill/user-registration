<?php
/**
 * UserRegistration Admin Settings Class
 *
 * @class    UR_Admin_User_Manager
 * @version  1.0.0
 * @package  UserRegistration/Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class UR_Admin_User_Manager
 */
class UR_Admin_User_Manager {
	/**
	 * The approved value in the db
	 */
	const APPROVED = 1;

	/**
	 * The pending value in the db
	 */
	const PENDING = 0;

	/**
	 * The deny value in the db
	 */
	const DENIED = -1;

	/**
	 * WP user object
	 *
	 * @var \WP_User
	 */
	private $user;

	/**
	 * The status of the user
	 *
	 * @var int
	 */
	private $user_status = null;

	/**
	 * UR_Admin_User_Manager constructor.
	 *
	 * @param null $user user.
	 *
	 * @throws Exception .
	 */
	public function __construct( $user = null ) {
		if ( is_null( $user ) ) {
			$user = get_userdata( get_current_user_id() );
		} elseif ( is_numeric( $user ) ) {
			$user = get_userdata( $user );
		}

		$user_instance_check = $user instanceof WP_User;

		/**
		 * Filter to check  user instancec.
		 *
		 * @param boolean User instancce found.
		 */
		$user_instance_check = apply_filters( 'user_registration_user_instance_check', $user_instance_check, $user );

		if ( ! $user_instance_check ) {
			throw new Exception( esc_html__( 'Impossible to create an UR_Admin_User_Manager object. Unkwon data type.', 'user-registration' ) );
		}

		$this->user = $user;
	}


	/**
	 * Save a new status for the user
	 *
	 * @param string $status status.
	 * @param int    $alert_user alert_user.
	 *
	 * @return bool|int $meta_status meta status.
	 */
	public function save_status( $status, $alert_user = true ) {

		/**
		 * Action to update User Status
		 *
		 * @param string $status User status
		 * @param int $this->user->ID User ID
		 * @param int|true $alert_user Flag whether to alert user or not
		 */
		do_action( 'ur_user_status_updated', $status, $this->user->ID, $alert_user );

		$action_label = '';
		$form_id      = get_user_meta( $this->user->ID, 'ur_form_id', true );
		$login_option = ur_get_user_login_option( $this->user->ID );

		switch ( $status ) {
			case self::APPROVED:
				$action_label = 'approved';
				if ( 'admin_approval_after_email_confirmation' === $login_option ) {
					update_user_meta( $this->user->ID, 'ur_admin_approval_after_email_confirmation', 'true' );
				} elseif ( 'email_confirmation' === $login_option ) {
					update_user_meta( $this->user->ID, 'ur_confirm_email', $status );
				}
				break;

			case self::PENDING:
				$action_label = 'pending';
				if ( 'admin_approval_after_email_confirmation' === $login_option ) {
					update_user_meta( $this->user->ID, 'ur_admin_approval_after_email_confirmation', 'false' );
				} elseif ( 'email_confirmation' === $login_option ) {
					update_user_meta( $this->user->ID, 'ur_confirm_email', $status );
				}
				break;

			case self::DENIED:
				$action_label = 'denied';
				if ( 'admin_approval_after_email_confirmation' === $login_option ) {
					update_user_meta( $this->user->ID, 'ur_admin_approval_after_email_confirmation', 'denied' );
				} elseif ( 'email_confirmation' === $login_option ) {
					update_user_meta( $this->user->ID, 'ur_confirm_email', $status );
				}
				break;
		}

		if ( ! empty( $action_label ) ) {
			/**
			 * Action to update User Meta according to Status
			 *
			 * @param int $this->user->ID User ID
			 */
			do_action( 'ur_user_' . $action_label, $this->user->ID );
		}
		$this->user_status = $status;

		return update_user_meta( absint( $this->user->ID ), 'ur_user_status', sanitize_text_field( $status ) );
	}

	/**
	 * Approve the user
	 *
	 * @return bool|int
	 */
	public function approve() {
		return $this->save_status( self::APPROVED );
	}

	/**
	 * Deny the user
	 *
	 * @return bool|int
	 */
	public function deny() {
		return $this->save_status( self::DENIED );
	}

	/**
	 * Approve or deny the user and keep their email confirmation in step.
	 *
	 * Shared by every admin screen and the AI abilities, so they all behave the same.
	 *
	 * @since xx.xx.xx
	 *
	 * @param bool $approve True to approve, false to deny.
	 * @return bool|int Result of saving the status.
	 */
	public function set_approval( $approve ) {
		$user_id      = absint( $this->user->ID );
		$login_option = ur_get_user_login_option( $user_id );
		$result       = $approve ? $this->approve() : $this->deny();

		if ( in_array( $login_option, array( 'email_confirmation', 'admin_approval_after_email_confirmation' ), true ) ) {
			update_user_meta( $user_id, 'ur_confirm_email', $approve ? '1' : '0' );
			delete_user_meta( $user_id, 'ur_confirm_email_token' );

			if ( 'admin_approval_after_email_confirmation' === $login_option ) {
				update_user_meta( $user_id, 'ur_admin_approval_after_email_confirmation', $approve ? 'true' : 'denied' );
			}
		}

		return $result;
	}

	/**
	 * Whether the current user may delete this user. Nobody can delete themselves.
	 *
	 * @since xx.xx.xx
	 *
	 * @return bool
	 */
	public function can_be_deleted() {
		$user_id = absint( $this->user->ID );

		return $user_id && get_current_user_id() !== $user_id && current_user_can( 'delete_user', $user_id );
	}

	/**
	 * Delete the user the way the Users and Members screens do. On multisite this only removes them from this site.
	 *
	 * Shared by every admin screen and the AI abilities, so they all behave the same.
	 *
	 * @since xx.xx.xx
	 *
	 * @param int|null $reassign User ID to give the user's posts and links to, or null to delete them.
	 * @return true|WP_Error
	 */
	public function delete( $reassign = null ) {
		$user_id = absint( $this->user->ID );

		if ( ! $this->can_be_deleted() ) {
			return new WP_Error(
				'edit_users',
				sprintf(
					/* translators: %s: Username */
					esc_html__( 'Sorry, you are not allowed to delete the user %s.', 'user-registration' ),
					esc_html( $this->user->user_login )
				)
			);
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		// Remove the profile picture and its details before the user is gone.
		do_action( 'ur_remove_profile_pictures_and_metadata', $user_id );

		return wp_delete_user( $user_id, $reassign ) ? true : new WP_Error( 'edit_users', esc_html__( 'The user could not be deleted.', 'user-registration' ) );
	}

	/**
	 * Whether any of the users own posts or links, so deleting them needs a choice about that content.
	 *
	 * @since xx.xx.xx
	 *
	 * @param int[] $user_ids User IDs.
	 * @return bool
	 */
	public static function users_have_content( $user_ids ) {
		global $wpdb;

		$user_ids = array_filter( array_map( 'absint', (array) $user_ids ) );

		/**
		 * Check whether the user to be deleted has additional content in the site.
		 *
		 * @since 4.1
		 */
		$users_have_content = (bool) apply_filters( 'user_registration_users_have_additional_content', false, $user_ids );

		if ( $user_ids && ! $users_have_content ) {
			$in                 = implode( ',', $user_ids );
			$users_have_content = (bool) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_author IN( {$in} ) LIMIT 1" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- IDs are cast to integers above.
				|| (bool) $wpdb->get_var( "SELECT link_id FROM {$wpdb->links} WHERE link_owner IN( {$in} ) LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- IDs are cast to integers above.
		}

		return $users_have_content;
	}

	/**
	 * Get the status of the user.
	 * If the status is not present (user registered when plugin was not active)
	 * then it return an empty string if $exact_value == true, otherwise it return approved flag
	 *
	 * @param bool $exact_value exact value.
	 *
	 * @return int|mixed $user_status user status.
	 */
	public function get_user_status( $exact_value = false ) {

		// If the status is already get from the db and the requested status is not the exact value then provide the old one.
		if ( ! is_null( $this->user_status ) && ! $exact_value ) {
			return $this->user_status;
		}

		$user_login_option                              = get_user_meta( $this->user->ID, 'ur_login_option', true );
		$user_status                                    = get_user_meta( $this->user->ID, 'ur_user_status', true );
		$user_email_status                              = get_user_meta( $this->user->ID, 'ur_confirm_email', true );
		$admin_approval_after_email_confirmation_status = get_user_meta( $this->user->ID, 'ur_admin_approval_after_email_confirmation', true );

		$result = '';

		if('sms_verification' === $user_login_option ){
			/**
			 * Case: SMS Verification.
			 */
			$this->user_status = $user_status;

			$result = array(
				'login_option' => $user_login_option,
				'user_status'  => $user_status,
			);
			return $result;

		}

		if ( '' === $user_status && '' === $user_email_status ) {
			// If the exact_value is true, allow to understand if an user has status "approved" or has registered when the plugin wash not active.
			if ( $exact_value ) {
				return $user_status;
			}

			// If the status is empty it's assume that user registered when the plugin was not active, then it is allowed.
			$user_status = self::APPROVED;

			// If the value requested is not the exact value, than store it in the object.
			$this->user_status = $user_status;

			$result = array(
				'login_option' => 'default',
				'user_status'  => $user_status,
			);
		} elseif ( '' !== $user_status && '' === $user_email_status ) {
			/**
			 * Case: Admin Approval.
			 */
			$this->user_status = $user_status;

			$result = array(
				'login_option' => 'admin_approval',
				'user_status'  => $user_status,
			);
		} elseif ( '' !== $admin_approval_after_email_confirmation_status && '' !== $user_email_status ) {
			/**
			 * Case: Admin Approval after Email Confirmation.
			 */
			if ( 'denied' === $admin_approval_after_email_confirmation_status ) {
				$admin_approval_after_email_confirmation_status = self::DENIED;
			} elseif ( ! ur_string_to_bool( $admin_approval_after_email_confirmation_status ) && ur_string_to_bool( $user_email_status ) ) {
				$admin_approval_after_email_confirmation_status = self::PENDING;
			} elseif ( ! ur_string_to_bool( $admin_approval_after_email_confirmation_status ) && ! ur_string_to_bool( $user_email_status ) ) {
				$admin_approval_after_email_confirmation_status = self::PENDING;
			} elseif ( $admin_approval_after_email_confirmation_status ) {
				$admin_approval_after_email_confirmation_status = self::APPROVED;
			}
			$this->user_status = $admin_approval_after_email_confirmation_status;

			$result = array(
				'login_option'    => 'admin_approval_after_email_confirmation',
				'user_status'     => $admin_approval_after_email_confirmation_status,
				'email_status'    => $user_email_status,
				'approval_status' => $admin_approval_after_email_confirmation_status,
			);
		} elseif ( ( '' !== $user_email_status ) ) {
			/**
			 * Case: Email Confirmation.
			 */

			$this->user_status = $user_email_status;

			$result = array(
				'login_option'    => 'email_confirmation',
				'user_status'     => '' == $user_status ? $user_email_status : $user_status,
				'email_status'    => $user_email_status,
				'approval_status' => $user_status,
			);
		}
		return $result;
	}

	/**
	 * Check if the user is approved
	 *
	 * @return bool
	 */
	public function is_approved() {
		$user_status = $this->get_user_status();

		if ( is_array( $user_status ) ) {
			return ( self::APPROVED == $user_status['user_status'] );
		}
		return ( self::APPROVED == $user_status );
	}

	/**
	 * Check if the user is pending.
	 *
	 * @return bool
	 */
	public function is_pending() {
		$user_status = $this->get_user_status();

		if ( is_array( $user_status ) ) {
			return ( self::PENDING == $user_status['user_status'] );
		}
		return ( self::PENDING == $user_status );
	}

	/**
	 * Check if the user is denied
	 *
	 * @return bool
	 */
	public function is_denied() {
		$user_status = $this->get_user_status( true );

		if ( is_array( $user_status ) ) {

			if ( isset( $user_status['approval_status'] ) ) {
				return self::DENIED == $user_status['approval_status'];
			}

			return ( self::DENIED == $user_status['user_status'] );
		}
		return ( self::DENIED == $user_status );
	}

	/**
	 * Check if the user is awaiting email confirmation.
	 *
	 * @return bool
	 */
	public function is_email_pending() {
		$user_status = $this->get_user_status( true );

		if ( is_array( $user_status ) ) {
			if (
				'admin_approval_after_email_confirmation' === $user_status['login_option'] ||
				'email_confirmation' === $user_status['login_option']
			) {
				if ( empty( $user_status['approval_status'] ) ) {
					if ( 0 == $user_status['email_status'] || 'false' == $user_status['email_status'] ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Create a new password if it have to be sent to the user and return it.
	 * If the password have not to be sent, it return an empty string.
	 *
	 * @return string
	 */
	public function reset_password() {
		$password = '';

		/**
		 * Filter to Avoid Password Reset
		 *
		 * @param boolean Aviod Reset or not
		 */
		$avoid_password_reset = apply_filters( 'ur_avoid_password_reset', false );
		if ( $avoid_password_reset ) {
			return $password;
		}

		// If the first_access_flag is equal to "" it means that user has registered when the plugin was not active, then don't reset.
		// If the first_access_flag is equal to 1 it means that user has has already loggedin at least one time, then don't reset.
		$first_access_flag = $this->get_first_access_flag();
		if ( 1 == $first_access_flag ) {
			return $password;
		}

		$password = wp_generate_password( 12, false );
		wp_set_password( $password, $this->user->ID );

		return $password;
	}

	/**
	 * Save a flag to recognize if an user has ever logged in
	 */
	public function save_first_access_flag() {
		if ( ! get_user_meta( $this->user->ID, 'ur_first_access' ) ) {
			add_user_meta( $this->user->ID, 'ur_first_access', 1 );
		}
	}

	/**
	 * Save a flag from the db to recognize if an user has ever logged  in
	 *
	 * @return mixed
	 */
	public function get_first_access_flag() {
		return get_user_meta( $this->user->ID, 'ur_first_access', true );
	}

	/**
	 * Check if the user has permissions to change the status of another user
	 *
	 * @return bool
	 */
	public function is_allowed_to_change_users_status() {

		$user_can = user_can( $this->user, 'edit_users' );

		/**
		 * Filter to Allow user to change status
		 *
		 * @param boolean $user_can User can change or not
		 */
		return apply_filters( 'ur_is_user_allowed_to_change_status', $user_can, $this->user->ID );
	}

	/**
	 * Check if the instanced user can change status of the user passed by parameter
	 *
	 * @param int $user_id user_id.
	 *
	 * @return bool
	 */
	public function can_change_status_of( $user_id ) {

		// The instanced user is not able to update statuses at all.
		if ( ! $this->is_allowed_to_change_users_status() ) {
			return false;
		}

		// The instanced user is the same user who the status have to be changed.
		if ( $this->user->ID == $user_id ) {
			return false;
		}

		// If the changer user has the capability "edit_users" but not "manage_options" (isn't an admin),
		// then allow to edit the status of another user only if him hasn't capability "manage_options" (isn't an admin).
		if ( ! user_can( $this->user, 'manage_options' ) && user_can( $user_id, 'manage_options' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if the approval status of the instanced user can be changd by the user passed by parameter
	 *
	 * @param null|int|\WP_User $user if this value is null, it is considered the current user.
	 *
	 * @return bool
	 */
	public function can_status_be_changed_by( $user = null ) {

		$user_changer = new self( $user );

		return $user_changer->can_change_status_of( $this->user->ID );
	}

	/**
	 * Check if a certain user (passed by parameter) is allowed to change approval status of other users
	 * If user id is not passed by parameter, it will be user the current user id
	 *
	 * @param null $user_id user_id.
	 *
	 * @return bool
	 */
	public static function is_user_allowed_to_change_status( $user_id = null ) {

		$user_manager = new static( $user_id );

		return $user_manager->is_allowed_to_change_users_status();
	}

	/**
	 * Get status label
	 *
	 * @param string $status status.
	 *
	 * @return string
	 */
	public static function get_status_label( $status ) {
		if ( self::APPROVED == $status ) {
			$label = esc_html__( 'approved', 'user-registration' );
		}

		if ( self::PENDING == $status ) {
			$label = esc_html__( 'pending', 'user-registration' );
		}

		if ( self::DENIED == $status ) {
			$label = esc_html__( 'denied', 'user-registration' );
		}

		return ucfirst( $label );
	}

	/**
	 * Returns the status code for the specified status label.
	 *
	 * @param [string] $status_label Status label.
	 *
	 * @since 3.1.0
	 *
	 * @return string or int
	 */
	public static function get_status_code( $status_label ) {
		$status_code = '';

		$status_map = array(
			'approved' => 1,
			'pending'  => 0,
			'denied'   => -1,
		);

		if ( isset( $status_map[ $status_label ] ) ) {
			return $status_map[ $status_label ];
		}

		return $status_code;
	}

	/**
	 * Label for user awaiting email confirmation.
	 *
	 * @return string
	 */
	public static function email_pending_label() {
		return esc_html__( 'Awaiting Email Confirmation', 'user-registration' );
	}

	/**
	 * Check if the status passed by parameter is a valid status
	 *
	 * @param string $status status.
	 *
	 * @return bool
	 */
	public static function validate_status( $status ) {
		return ( self::APPROVED == $status || self::PENDING == $status || self::DENIED == $status );
	}
}
