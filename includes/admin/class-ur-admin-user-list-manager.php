<?php
/**
 * UserRegistration Admin Settings Class
 *
 * @class    UR_Admin_User_List_Manager
 * @version  1.0.0
 * @package  UserRegistration/Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Manager of the users list in the backend
 *
 * Class UR_Admin_User_List_Manager
 *
 * @package ComponentManualUserApprove
 */
class UR_Admin_User_List_Manager {

	/**
	 * UR_Admin_User_List_Manager constructor.
	 */
	public function __construct() {

		// -------------------- ACTIONS & FILTERS --------------------
		add_action( 'load-users.php', array( $this, 'trigger_query_actions' ) );
		add_action( 'admin_notices', array( $this, 'user_registration_display_admin_notices' ), 99 );
		add_action( 'admin_notices', array( $this, 'user_registration_pending_users_notices' ) );
		add_action( 'admin_notices', array( $this, 'user_registration_non_urm_users_notices' ) );
		// Functions about users listing.
		add_action( 'restrict_manage_users', array( $this, 'add_status_filter' ) );
		add_action( 'admin_footer-users.php', array( $this, 'add_bulk_actions' ) );
		add_action( 'load-users.php', array( $this, 'trigger_bulk_action' ) );

		// Handle the status field in the profile page of users in backend.
		add_action( 'show_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_field' ) );
		add_filter( 'user_row_actions', array( $this, 'create_quick_links' ), 10, 2 );
		add_filter( 'manage_users_columns', array( $this, 'add_column_head' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'add_column_cell' ), 10, 3 );
		add_filter( 'manage_users_sortable_columns', array( $this, 'make_registered_at_column_sortable' ) );
		add_filter( 'pre_get_users', array( $this, 'filter_users_by_approval_status' ) );
	}

	/**
	 * Create two quick links Approve and Deny for each user in the users list.
	 *
	 * @param  array  $actions the approve or pending action.
	 * @param  object $user User data.
	 *
	 * @return array
	 */
	public function create_quick_links( $actions, $user ) {

		$user_manager = new UR_Admin_User_Manager( $user );

		if ( ! $user_manager->can_status_be_changed_by( get_current_user_id() ) ) {
			return $actions;
		}

		$approve_link = add_query_arg(
			array(
				'action' => 'approve',
				'user'   => $user->ID,
			)
		);
		$approve_link = remove_query_arg( array( 'new_role' ), $approve_link );
		$approve_link = wp_nonce_url( $approve_link, 'ur_user_change_status' );

		$deny_link = add_query_arg(
			array(
				'action' => 'deny',
				'user'   => $user->ID,
			)
		);
		$deny_link = remove_query_arg( array( 'new_role' ), $deny_link );
		$deny_link = wp_nonce_url( $deny_link, 'ur_user_change_status' );

		$resend_verification_link = add_query_arg(
			array(
				'action' => 'resend_verification',
				'user'   => $user->ID,
			)
		);
		$resend_verification_link = remove_query_arg( array( 'new_role' ), $resend_verification_link );
		$resend_verification_link = wp_nonce_url( $resend_verification_link, 'ur_user_change_email_status' );

		$resend_verification_action = '<a href="' . esc_url( $resend_verification_link ) . '">' . _x( 'Resend Verification', 'The action on users list page', 'user-registration' ) . '</a>';
		$approve_action             = '<a style="color:#086512" href="' . esc_url( $approve_link ) . '">' . _x( 'Approve', 'The action on users list page', 'user-registration' ) . '</a>';
		$deny_action                = '<a style="color:#e20707" href="' . esc_url( $deny_link ) . '">' . _x( 'Deny', 'The action on users list page', 'user-registration' ) . '</a>';

		$user_status = $user_manager->get_user_status();

		if ( 0 == $user_status['user_status'] ) {
			$actions['ur_user_deny_action']    = $deny_action;
			$actions['ur_user_approve_action'] = $approve_action;

			if ( 'admin_approval_after_email_confirmation' === $user_status['login_option'] || 'email_confirmation' === $user_status['login_option'] ) {
				$actions['ur_user_resend_verification_action'] = $resend_verification_action;
			}
		} elseif ( 1 == $user_status['user_status'] ) {
			$actions['ur_user_deny_action'] = $deny_action;
		} elseif ( -1 == $user_status['user_status'] ) {
			$actions['ur_user_approve_action'] = $approve_action;
		}

		return $actions;
	}

	/**
	 * Create quick links in users table.
	 *
	 * @deprecated 1.8.7
	 *
	 * @param  array  $actions the approve or pending action.
	 * @param  string $user The id of the user.
	 * @return void
	 */
	public function ceate_quick_links( $actions, $user ) {
		ur_deprecated_function( 'UR_Email_Confirmation::ceate_quick_links', '1.8.7', 'UR_Email_Confirmation::create_quick_links' );
	}

	/**
	 * Trigger the action query and check if some users have been approved or denied
	 */
	public function trigger_query_actions() {

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : false;
		$mode   = isset($_POST['mode']) ? $_POST['mode'] : false; // phpcs:ignore

		// If this is a multisite, bulk request, stop now!
		if ( 'list' == $mode ) {
			return;
		}

		if ( ! empty( $action ) && in_array( $action, array( 'approve', 'deny' ) ) && ! isset( $_GET['new_role'] ) ) {

			check_admin_referer( 'ur_user_change_status' );

			$redirect     = admin_url( 'users.php' );
			$status       = $action;
			$user_id      = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
			$user_manager = new UR_Admin_User_Manager( $user_id );
			$login_option = ur_get_user_login_option( $user_id );

			if ( 'approve' === $status ) {

				$user_manager->approve();
				$redirect = add_query_arg( array( 'approved' => 1 ), $redirect );
				if ( 'email_confirmation' === $login_option || 'admin_approval_after_email_confirmation' === $login_option ) {
					update_user_meta( $user_id, 'ur_confirm_email', '1' );
					delete_user_meta( $user_id, 'ur_confirm_email_token' );
					if ( 'admin_approval_after_email_confirmation' === $login_option ) {
						update_user_meta( $user_id, 'ur_admin_approval_after_email_confirmation', 'true' );
					}
				}
			} else {

				$user_manager->deny();
				$redirect = add_query_arg( array( 'denied' => 1 ), $redirect );
				if ( 'email_confirmation' === $login_option || 'admin_approval_after_email_confirmation' === $login_option ) {
					update_user_meta( $user_id, 'ur_confirm_email', '0' );
					delete_user_meta( $user_id, 'ur_confirm_email_token' );
					if ( 'admin_approval_after_email_confirmation' === $login_option ) {
						update_user_meta( $user_id, 'ur_admin_approval_after_email_confirmation', 'denied' );
					}
				}
			}

			/**
			 * Filter to modify redirect url
			 *
			 * @param string $redirect Redirect URL
			 */
			wp_safe_redirect( esc_url_raw( apply_filters( 'user_registration_admin_action_redirect', $redirect ) ) );
			exit;
		}
	}

	/**
	 * Display a notice to admin notifying the pending users.
	 */
	public function user_registration_pending_users_notices() {
		global $wpdb;

		// Remove previously set filter to get exact pending users count.
		remove_filter( 'pre_get_users', array( $this, 'filter_users_by_approval_status' ) );
		$prefix = $wpdb->prefix;
		if ( is_multisite() ) {
			$prefix = $wpdb->base_prefix;
		}

		$users = $wpdb->get_results(
			"SELECT *
			FROM {$prefix}users AS users
			JOIN {$prefix}usermeta AS meta1 ON users.ID = meta1.user_id
			WHERE meta1.meta_key = 'ur_user_status' AND meta1.meta_value = '0'
			AND (
				((meta1.meta_key = 'ur_confirm_email' AND meta1.meta_value != '0')
				OR NOT EXISTS (
					SELECT 1 FROM {$prefix}usermeta WHERE user_id = users.ID AND meta_key = 'ur_confirm_email'
				))
				OR
				(
				(meta1.meta_key = 'ur_admin_approval_after_email_confirmation' AND meta1.meta_value = 'false')
				OR NOT EXISTS (
					SELECT 1 FROM {$prefix}usermeta WHERE user_id = users.ID AND meta_key = 'ur_admin_approval_after_email_confirmation'
				)
			));"
		);

		// Get the results from the query, returning the first user.
		$current_screen = get_current_screen();
		$ur_pages       = ur_get_screen_ids();
		array_push( $ur_pages, 'users' );

		// Check if Users are Pending and display pending users notice in UR and Users.
		if ( count( $users ) > 0 && in_array( $current_screen->id, $ur_pages ) ) {
			$admin_url = admin_url( '', 'admin' ) . 'users.php?s&action=-1&new_role&ur_user_approval_status=pending&ur_user_filter_action=Filter&paged=1&action2=-1&new_role2&ur_user_approval_status2&ur_specific_form_user2';
			echo '<div id="user-approvation-result" class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'User Registration & Membership:', 'user-registration' ) . '</strong> ' . esc_html( count( $users ) ) . ' <a href="' . esc_url( $admin_url ) . '">' . ( ( count( $users ) === 1 ) ? esc_html__( 'User', 'user-registration' ) : esc_html__( 'Users', 'user-registration' ) ) . '</a> ' . esc_html__( 'pending approval.', 'user-registration' ) . '</p></div>';
		}
	}
	/**
	 * Display a notice to admin notifying the users registered via non URM forms.
	 */
	public function user_registration_non_urm_users_notices() {

		$current_user_id = get_current_user_id();

		$urm_dismissed_notices = get_user_meta( $current_user_id, 'urm_dismissed_notices', true );

		if ( is_array( $urm_dismissed_notices ) && isset( $urm_dismissed_notices['non_urm_users_notice'] ) ) {
			$closed_count = isset( $urm_dismissed_notices['non_urm_users_notice']['dismiss_count'] ) ?: 0;
			$last_closed  = isset( $urm_dismissed_notices['non_urm_users_notice']['last_dismissed_at'] ) ?: 0;
			// if it was closed twice in a row, don't show again.
			if ( $closed_count >= 2 ) {
				return;
			}
			// Don't show if it has been closed recently(within past 24 hours).
			if ( $last_closed && ( time() - $last_closed ) < DAY_IN_SECONDS ) {
				return;
			}
		}
		$users          = get_transient( 'urm_users_not_from_urm_forms' );
		$current_screen = get_current_screen();
		$ur_pages       = ur_get_screen_ids();
		array_push( $ur_pages, 'users' );

		$existing_non_urm_users = $users ? $users : 0;
		if ( $existing_non_urm_users >= 5 && in_array( $current_screen->id, $ur_pages ) ) {
			$utm_url = esc_url(
				ur_utm_url(
					'https://wpuserregistration.com/features/profile-connect/',
					array(
						'source'  => 'admin-notices',
						'medium'  => 'button',
						'content' => 'profile-connect',
					)
				)
			);

			?>
			<div class="notice notice-info is-dismissible urm-per-user-notice urm-non-urm-users-notice" data-notice-id="non_urm_users_notice" data-notice-type="urm-admin-notice">
				<p>
					<strong><?php esc_html_e( 'User Registration & Membership:', 'user-registration' ); ?></strong>
					<?php
					printf(
						/* translators: %s: number of existing users */
						esc_html__( 'Your site has %s users registered before this plugin.', 'user-registration' ),
						'<span class="highlight">' . intval( $existing_non_urm_users ) . '</span>'
					);
					?>
					<?php esc_html_e( 'Want them to enjoy the new profile features too? Use the', 'user-registration' ); ?>
					<a class="addon-link" href="<?php echo $utm_url; ?>" rel="noreferrer noopener" target="_blank">
						<?php esc_html_e( 'Profile Connect addon', 'user-registration' ); ?>
					</a>
					<?php esc_html_e( 'to link these existing users to your new registration form.', 'user-registration' ); ?>
				</p>
			</div>
			<?php
		}
	}
	/**
	 * Deprecates old plugin missing notice.
	 *
	 * @deprecated 1.9.0
	 *
	 * @return void
	 */
	public function pending_users_notices() {
		ur_deprecated_function( 'UR_Admin_User_List_Manager::pending_users_notices', '1.9.0', 'UR_Admin_User_List_Manager::user_registration_pending_users_notices' );
	}

	/**
	 * Display a notice to admin if some users have been approved or denied
	 */
	public function user_registration_display_admin_notices() {
		$screen = get_current_screen();

		if ( 'users' !== $screen->id ) {
			return;
		}

		$message        = null;
		$users_denied   = ( isset( $_GET['denied'] ) && is_numeric( $_GET['denied'] ) ) ? absint( $_GET['denied'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$users_approved = ( isset( $_GET['approved'] ) && is_numeric( $_GET['approved'] ) ) ? absint( $_GET['approved'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $users_approved ) {
			/* translators: %s - Number of users approved. */
			$message = sprintf( __( 'User Approved: %s users approved.', 'user-registration' ), $users_approved );
		} elseif ( $users_denied ) {
			/* translators: %s - Number of users denied. */
			$message = sprintf( __( 'User Denied: %s users denied.', 'user-registration' ), $users_denied );
		}

		if ( ! empty( $message ) ) {
			echo '<div id="user-approvation-result" class="notice notice-success is-dismissible"><p><strong>' . esc_html( $message ) . '</strong></p></div>';
		}
	}

	/**
	 * Deprecates old plugin missing notice.
	 *
	 * @deprecated 1.9.0
	 *
	 * @return void
	 */
	public function display_admin_notices() {
		ur_deprecated_function( 'UR_Admin_User_List_Manager::display_admin_notices', '1.9.0', 'UR_Admin_User_List_Manager::user_registration_display_admin_notices' );
	}

	/**
	 * Add the column header for the status column
	 *
	 * @param array $columns Columns.
	 *
	 * @return array
	 */
	public function add_column_head( $columns ) {

		$the_columns['ur_user_user_registered_source'] = esc_html__( 'Source', 'user-registration' );
		$the_columns['ur_user_user_registered_log']    = esc_html__( 'Registered At', 'user-registration' );
		$newcol                                        = array_slice( $columns, 0, -1 );
		$newcol                                        = array_merge( $newcol, $the_columns );
		$columns                                       = array_merge( $newcol, array_slice( $columns, 1 ) );
		return $columns;
	}

	/**
	 * Set the status value for each user in the users list
	 *
	 * @param string $val Status Value.
	 * @param string $column_name Name of the column.
	 * @param int    $user_id User ID.
	 *
	 * @return string
	 */
	public function add_column_cell( $val, $column_name, $user_id ) {

		if ( 'ur_user_user_status' === $column_name ) {
			$user_manager = new UR_Admin_User_Manager( $user_id );
			$status       = $user_manager->get_user_status();

			if ( in_array( $status['login_option'], array( 'email_confirmation', 'admin_approval_after_email_confirmation' ), true ) ) {
				if ( '0' == $status['approval_status'] || '' == $status['approval_status'] ) {
					if ( 0 == $status['email_status'] || 'false' == $status['email_status'] ) {
						return __( 'Awaiting Email Confirmation', 'user-registration' );
					}
				} elseif ( '-1' == $status['approval_status'] ) {
					return UR_Admin_User_Manager::get_status_label( '-1' );
				}
			}

			if ( ! empty( $status ) ) {
				if ( $user_manager->is_denied() ) {
					return UR_Admin_User_Manager::get_status_label( '-1' );
				}

				if ( $user_manager->is_email_pending() ) {
					return UR_Admin_User_Manager::email_pending_label();
				}

				return UR_Admin_User_Manager::get_status_label( $status['user_status'] );
			}
		} elseif ( 'ur_user_user_registered_source' === $column_name ) {
			$user_metas = get_user_meta( $user_id );

			if ( isset( $user_metas['user_registration_social_connect_bypass_current_password'] ) ) {
				$networks = array( 'facebook', 'linkedin', 'google', 'twitter' );

				foreach ( $networks as $network ) {

					if ( isset( $user_metas[ 'user_registration_social_connect_' . $network . '_username' ] ) ) {
						return ucfirst( $network );
					}
				}
			} elseif ( isset( $user_metas['ur_form_id'] ) ) {
				$form_post = get_post( $user_metas['ur_form_id'][0] );

				if ( ! empty( $form_post ) ) {
					return $form_post->post_title;
				} else {
					return '-';
				}
			} else {
				return '-';
			}
		} elseif ( 'ur_user_user_registered_log' === $column_name ) {
			$user_data      = get_userdata( $user_id );
			$registered_log = $user_data->user_registered;

			if ( $user_data ) {
				/**
				 * Filter to modify Date Format
				 *
				 * @param string Date Format
				 */
				$date_format = apply_filters( 'user_registration_registered_log_date_format', 'F j Y , h:i A' );
				$log         = date_i18n( $date_format, strtotime( str_replace( '/', '-', $registered_log ) ) );
				return $log;
			} else {
				return '-';
			}
		}
		return $val;
	}

	/**
	 * Make our "Registration At" column sortable
	 *
	 * @param array $columns Array of all user sortable columns.
	 */
	public function make_registered_at_column_sortable( $columns ) {
		return wp_parse_args( array( 'ur_user_user_registered_log' => 'user_registered' ), $columns );
	}

	/**
	 * Filter user list based upon approval status and specific user registration forms.
	 *
	 * @param string $which Used to determine which filter selector i.e. top or bottom is used to filter.
	 */
	public function add_status_filter( $which ) {

		// Get the filter selector id for approval status and the selected status.
		$status_id           = 'bottom' === $which ? 'ur_user_approval_status2' : 'ur_user_approval_status';
		$status_filter_value = ( isset( $_GET[ $status_id ] ) && ! empty( $_GET[ $status_id ] ) ) ? sanitize_text_field( wp_unslash( $_GET[ $status_id ] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Get the filter selector id for specific forms and the selected form id.
		$specific_form_id           = 'bottom' === $which ? 'ur_specific_form_user2' : 'ur_specific_form_user';
		$specific_form_filter_value = ( isset( $_GET[ $specific_form_id ] ) && ! empty( $_GET[ $specific_form_id ] ) ) ? sanitize_text_field( wp_unslash( $_GET[ $specific_form_id ] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$approved_label = UR_Admin_User_Manager::get_status_label( UR_Admin_User_Manager::APPROVED );
		$pending_label  = UR_Admin_User_Manager::get_status_label( UR_Admin_User_Manager::PENDING );
		$denied_label   = UR_Admin_User_Manager::get_status_label( UR_Admin_User_Manager::DENIED );

		$pending_email_label = __( 'Awaiting Email Confirmation', 'user-registration' );

		?>
</div><!-- .alignleft.actions opened in extra_tablenav() - class-wp-users-list-table.php:259 -->
<div class="alignleft actions">

	<!-- Filter for approval status. -->
	<label class="screen-reader-text"
		for="<?php echo esc_attr( $status_id ); ?>"><?php esc_html_e( 'All statuses', 'user-registration' ); ?></label>
	<select name="<?php echo esc_attr( $status_id ); ?>" id="<?php echo esc_attr( $status_id ); ?>">
		<option value=""><?php esc_html_e( 'All approval statuses', 'user-registration' ); ?></option>

		<?php
		echo '<option value="approved" ' . esc_attr( selected( 'approved', $status_filter_value ) ) . '>' . esc_html( $approved_label ) . '</option>';
		echo '<option value="pending" ' . esc_attr( selected( 'pending', $status_filter_value ) ) . '>' . esc_html( $pending_label ) . '</option>';
		echo '<option value="denied" ' . esc_attr( selected( 'denied', $status_filter_value ) ) . '>' . esc_html( $denied_label ) . '</option>';
		echo '<option value="pending_email" ' . esc_attr( selected( 'pending_email', $status_filter_value ) ) . '>' . esc_html( $pending_email_label ) . '</option>';
		?>
	</select>

	<!-- Filter for specific forms. -->
	<label class="screen-reader-text"
		for="<?php echo esc_attr( $specific_form_id ); ?>"><?php esc_html_e( 'All Forms', 'user-registration' ); ?></label>
	<select name="<?php echo esc_attr( $specific_form_id ); ?>" id="<?php echo esc_attr( $specific_form_id ); ?>">
		<option value=""><?php esc_html_e( 'All Sources', 'user-registration' ); ?></option>
		<option value="all_ur" <?php selected( 'all_ur', $specific_form_filter_value ); ?>>
			<?php esc_html_e( 'All UR Forms', 'user-registration' ); ?></option>

				<?php
				$all_forms = ur_get_all_user_registration_form();

				foreach ( $all_forms as $form_id => $form_name ) {
					echo '<option value="' . esc_attr( $form_id ) . '" ' . esc_attr( selected( $form_id, $specific_form_filter_value ) ) . ' >' . esc_html( $form_name ) . '</option>';
				}

				?>
			</select>
		<?php
		submit_button( esc_html__( 'Filter', 'user-registration' ), 'button', 'ur_user_filter_action', false );
	}

	/**
	 * Fire the filter selction and show only the users with specified approval status.
	 *
	 * @param object $query Database query.
	 */
	public function filter_users_by_approval_status( $query ) {

		$ur_user_filter_action = ( isset( $_REQUEST['ur_user_filter_action'] ) && ! empty( $_REQUEST['ur_user_filter_action'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['ur_user_filter_action'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Get the selected value of All UR Form from top or bottom All UR For filter.
		$all_ur_user  = ( isset( $_REQUEST['ur_specific_form_user'] ) && ! empty( $_REQUEST['ur_specific_form_user'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['ur_specific_form_user'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$all_ur_user2 = ( isset( $_REQUEST['ur_specific_form_user2'] ) && ! empty( $_REQUEST['ur_specific_form_user2'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['ur_specific_form_user2'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Get the selected value of user approval status from top or bottom user approval filter.
		$ur_user_approval_status  = ( isset( $_REQUEST['ur_user_approval_status'] ) && ! empty( $_REQUEST['ur_user_approval_status'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['ur_user_approval_status'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ur_user_approval_status2 = ( isset( $_REQUEST['ur_user_approval_status2'] ) && ! empty( $_REQUEST['ur_user_approval_status2'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['ur_user_approval_status2'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Get the selected id of specific form from top or bottom user form filter.
		$ur_specific_form_user  = ( isset( $_REQUEST['ur_specific_form_user'] ) && ! empty( $_REQUEST['ur_specific_form_user'] ) ) ? absint( wp_unslash( $_REQUEST['ur_specific_form_user'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ur_specific_form_user2 = ( isset( $_REQUEST['ur_specific_form_user2'] ) && ! empty( $_REQUEST['ur_specific_form_user2'] ) ) ? absint( wp_unslash( $_REQUEST['ur_specific_form_user2'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $ur_user_filter_action ) {
			return;
		}

		$status     = null;
		$form_id    = null;
		$meta_query = null;
		$ur_user    = null;
		if ( $ur_user_approval_status2 ) {
			$status = sanitize_text_field( $ur_user_approval_status2 );
		} elseif ( $ur_user_approval_status ) {
			$status = sanitize_text_field( $ur_user_approval_status );
		}

		if ( $ur_specific_form_user2 ) {
			$form_id = sanitize_text_field( $ur_specific_form_user2 );
		} elseif ( $ur_specific_form_user ) {
			$form_id = sanitize_text_field( $ur_specific_form_user );
		}

		if ( $all_ur_user2 ) {
			$ur_user = sanitize_text_field( $all_ur_user2 );
		} elseif ( $all_ur_user ) {
			$ur_user = sanitize_text_field( $all_ur_user );
		}
		// Deduct meta_query to filter user according to approve status.
		if ( isset( $status ) && '' !== $status ) {
			switch ( $status ) {
				case 'approved':
					$meta_query = $this->get_approved_users_meta_query();
					break;
				case 'pending':
					$meta_query = $this->get_pending_users_meta_query();
					break;
				case 'denied':
					$meta_query = $this->get_denied_users_meta_query();
					break;
				case 'pending_email':
					$meta_query = $this->get_pending_email_meta_query();
					break;
				default:
					return;
			}
		}

		// Deduct meta_query to filter user according to form id and approval status set.
		if ( isset( $form_id ) && '' !== $form_id ) {
			$meta_query = array(
				'relation' => 'AND',
				$meta_query,
				array(
					'key'     => 'ur_form_id',
					'value'   => $form_id,
					'compare' => '=',
				),
			);

		}
		if ( isset( $ur_user ) && 'all_ur' === $ur_user ) {
			$meta_query = array(
				'relation' => 'AND',
				$meta_query,
				array(
					'key' => 'ur_form_id',
				),
			);
		}
		$query->set( 'meta_query', $meta_query );
	}


	/**
	 * Seems that doesn't exists a properaction or filter that allow to add custom bulk actions, so this function add them
	 * in the select form at runtime, using javascript
	 */
	public function add_bulk_actions() {

		if ( ! UR_Admin_User_Manager::is_user_allowed_to_change_status() ) {
			return;
		}

		?>
			<script type="text/javascript">
				jQuery(document).ready(function() {
					jQuery('<option>').val('approve').text('<?php esc_html_e( 'Approve', 'user-registration' ); ?>')
						.appendTo("select[name='action']");
					jQuery('<option>').val('approve').text('<?php esc_html_e( 'Approve', 'user-registration' ); ?>')
						.appendTo("select[name='action2']");

					jQuery('<option>').val('deny').text('<?php esc_html_e( 'Deny', 'user-registration' ); ?>').appendTo(
						"select[name='action']");
					jQuery('<option>').val('deny').text('<?php esc_html_e( 'Deny', 'user-registration' ); ?>').appendTo(
						"select[name='action2']");

					jQuery('<option>').val('await_confirmation').text(
							'<?php esc_html_e( 'Resend Verification Email', 'user-registration' ); ?>')
						.appendTo(
							"select[name='action']");
					jQuery('<option>').val('await_confirmation').text(
							'<?php esc_html_e( 'Resend Verification Email', 'user-registration' ); ?>')
						.appendTo(
							"select[name='action2']");
				});
			</script>
		<?php
	}


	/**
	 * Trigger the bulk action approvation.
	 *
	 * @throws Exception Throw exception if permissions not met.
	 */
	public function trigger_bulk_action() {

		$wp_list_table = _get_list_table( 'WP_Users_List_Table' );
		$action        = $wp_list_table->current_action();
		$redirect      = 'users.php';

		// Check if the action is under the scope of this function.
		if ( 'approve' !== $action && 'deny' !== $action && 'await_confirmation' !== $action ) {
			return;
		}

		// Check if the current user has permissions to change approvation statuses.
		if ( ! UR_Admin_User_Manager::is_user_allowed_to_change_status() ) {
			throw new Exception( 'You have not enough permissions to perform a bulk action on users approval status' );
		}

		if ( empty( $_REQUEST['users'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( $redirect );
			exit();
		}

		if ( 'approve' === $action ) {
			$status    = UR_Admin_User_Manager::APPROVED;
			$query_arg = 'approved';
		} elseif ( 'await_confirmation' === $action ) {
			$status    = $action;
			$query_arg = 'await_confirmation';
		} else {
			$status    = UR_Admin_User_Manager::DENIED;
			$query_arg = 'denied';
		}

		$userids = wp_unslash( $_REQUEST['users'] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$c       = 0;

		foreach ( $userids as $id ) {
			$id = (int) $id;

			if ( 'await_confirmation' == $action ) {
				$user_manager = new UR_Admin_User_Manager( $id );
				$status       = $user_manager->is_email_pending();
				if ( $status ) {
					ur_resend_verification_email( $id );
					$redirect = add_query_arg( array( 'resend_verification_sent' => 1 ), $redirect );
				}
			} else {
				$user_manager = new UR_Admin_User_Manager( $id );
				// For each user, check if the current user can change him status.
				if ( ! $user_manager->can_status_be_changed_by( get_current_user_id() ) ) {
					continue;
				}

				$user_manager->save_status( $status );
			}

			++$c;
		}

		wp_safe_redirect( add_query_arg( $query_arg, $c, $redirect ) );
		exit();
	}

	/**
	 * Render the field Status in the user profile, in backend.
	 *
	 * @param object $user User data.
	 */
	public function render_profile_field( $user ) {

		$user_manager = new UR_Admin_User_Manager( $user );

		// If the current user can't change status of the user displayed, then return.
		if ( ! $user_manager->can_status_be_changed_by( get_current_user_id() ) ) {
			return;
		}

		$user_status = $user_manager->get_user_status();
		?>
		<table class="form-table">
			<tr>
				<th>
					<label for="ur_user_user_status"><?php esc_html_e( 'Approval Status', 'user-registration' ); ?></label>
				</th>
				<td>
					<select id="ur_user_user_status" name="ur_user_user_status">
						<?php
						$available_statuses = array( UR_Admin_User_Manager::APPROVED, UR_Admin_User_Manager::PENDING, UR_Admin_User_Manager::DENIED );
						foreach ( $available_statuses as $status ) :
							?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php esc_attr( selected( $status, $user_status['user_status'] ) ); ?>>
								<?php echo esc_html( UR_Admin_User_Manager::get_status_label( $status ) ); ?></option>
							<?php
						endforeach;
						?>
					</select>
					<span class="description"><?php esc_html_e( 'If user has access to sign in or not.', 'user-registration' ); ?></span>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Update the profile field Status in the user profile, in backend.
	 *
	 * @param int $user_id User id.
	 *
	 * @return bool
	 */
	public function save_profile_field( $user_id ) {
		$user_manager = new UR_Admin_User_Manager( $user_id );

		if ( ! current_user_can( 'edit_users', $user_id ) || ! $user_manager->can_status_be_changed_by( get_current_user_id() ) ) {
			return false;
		}

		if ( ( isset( $_POST['ur_user_user_status'] ) && empty( $_POST['ur_user_user_status'] ) && ! UR_Admin_User_Manager::validate_status( sanitize_text_field( wp_unslash( $_POST['ur_user_user_status'] ) ) ) ) && ( isset( $_POST['ur_user_email_confirmation_status'] ) && empty( $_POST['ur_user_email_confirmation_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}

		$user_status       = get_user_meta( $user_id, 'ur_user_status', true );
		$user_email_status = get_user_meta( $user_id, 'ur_confirm_email', true );

		if ( '' === $user_email_status && $user_status == $_POST['ur_user_user_status'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		} elseif ( '' !== $user_email_status && $user_status == $_POST['ur_user_user_status'] && $user_email_status == $_POST['ur_user_user_status'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		} elseif ( $user_manager->is_approved() && '1' == $_POST['ur_user_user_status'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}

		if ( isset( $_POST['ur_user_user_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$new_status = sanitize_text_field( wp_unslash( $_POST['ur_user_user_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$user_manager->save_status( $new_status );
		} elseif ( isset( $_POST['ur_user_email_confirmation_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$new_status = sanitize_text_field( wp_unslash( $_POST['ur_user_email_confirmation_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			return update_user_meta( absint( $user_id ), 'ur_confirm_email', $new_status );
		}
	}

	/**
	 * Returns meta query array to fetch approved users.
	 *
	 * @return array
	 */
	private function get_approved_users_meta_query() {
		$meta_query = array(
			'relation' => 'OR',
			array(
				'relation' => 'AND',
				array(
					'key'     => 'ur_user_status',
					'compare' => 'NOT EXISTS', // works!
					'value'   => '', // This is ignored, but is necessary...
				),
				array(
					'key'     => 'ur_confirm_email',
					'compare' => 'NOT EXISTS', // works!
					'value'   => '', // This is ignored, but is necessary...
				),
			),
			array(
				'relation' => 'AND',
				array(
					'key'   => 'ur_user_status',
					'value' => UR_Admin_User_Manager::APPROVED,
				),
				array(
					'key'     => 'ur_admin_approval_after_email_confirmation',
					'compare' => 'NOT EXISTS', // works!
					'value'   => '', // This is ignored, but is necessary...
				),
			),
			array(
				'relation' => 'AND',
				array(
					'key'   => 'ur_user_status',
					'value' => UR_Admin_User_Manager::APPROVED,
				),
				array(
					'key'   => 'ur_admin_approval_after_email_confirmation',
					'value' => true,
				),
			),
		);

		return $meta_query;
	}

	/**
	 * Returns meta query array to fetch pending users.
	 *
	 * @return array
	 */
	private function get_pending_users_meta_query() {
		$meta_query = array(
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key'     => 'ur_user_status',
					'value'   => '0',
					'compare' => '=',
				),
				array(
					'relation' => 'AND',
					array(
						'relation' => 'OR',
						array(
							'key'     => 'ur_confirm_email',
							'value'   => '0',
							'compare' => '!=',
						),
						array(
							'key'     => 'ur_confirm_email',
							'compare' => 'NOT EXISTS',
						),
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => 'ur_admin_approval_after_email_confirmation',
							'value'   => 'false',
							'compare' => '=',
						),
						array(
							'key'     => 'ur_admin_approval_after_email_confirmation',
							'compare' => 'NOT EXISTS',
						),
					),
				),
			),
		);

		return $meta_query;
	}

	/**
	 * Returns meta query array to fetch denied users.
	 *
	 * @return array
	 */
	private function get_denied_users_meta_query() {
		$meta_query = array(
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key'     => 'ur_user_status',
					'value'   => '-1',
					'compare' => '=',
				),
				array(
					'key'     => 'ur_confirm_email',
					'value'   => '-1',
					'compare' => '=',
				),
			),
		);

		return $meta_query;
	}

	/**
	 * Returns meta query array to fetch email pending users.
	 *
	 * @return array
	 */
	private function get_pending_email_meta_query() {
		$meta_query = array(
			'meta_query' => array(

				array(
					'relation' => 'AND',
					array(
						'relation' => 'OR',
						array(
							'key'     => 'ur_user_status',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => 'ur_user_status',
							'value'   => '0',
							'compare' => '=',
						),
					),
					array(
						'key'     => 'ur_confirm_email',
						'value'   => '0',
						'compare' => '=',
					),
				),
			),
		);

		return $meta_query;
	}
}

return new UR_Admin_User_List_Manager();
