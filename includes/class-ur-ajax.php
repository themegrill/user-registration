<?php
/**
 * UserRegistration UR_AJAX
 *
 * AJAX Event Handler
 *
 * @package UserRegistration/Classes
 * @class   UR_AJAX
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UR_AJAX Class
 */
class UR_AJAX {

	/**
	 * Field key array
	 *
	 * @var array
	 */
	private static $field_key_aray = array();
	/**
	 * Check whether is field key pass
	 *
	 * @var bool
	 */
	private static $is_field_key_pass = true;
	/**
	 * Field key value
	 *
	 * @var array
	 */
	private static $failed_key_value = array();

	/**
	 * Initialization of ajax.
	 */
	public static function init() {
		self::add_ajax_events();
	}

	/**
	 * Hook in methods - uses WordPress ajax handlers (admin-ajax)
	 */
	public static function add_ajax_events() {
		// Add nocache headers to all AJAX requests
		add_action( 'wp_ajax_user_registration_nocache_headers', array( __CLASS__, 'add_nocache_headers' ) );
		add_action( 'wp_ajax_nopriv_user_registration_nocache_headers', array( __CLASS__, 'add_nocache_headers' ) );

		$ajax_events = array(
			'user_input_dropped'                   => true,
			'user_form_submit'                     => true,
			'get_recent_nonce'                     => true,
			'update_profile_details'               => true,
			'profile_pic_upload'                   => true,
			'ajax_login_submit'                    => true,
			'send_test_email'                      => false,
			'create_form'                          => false,
			'rated'                                => false,
			'dashboard_widget'                     => false,
			'dismiss_notice'                       => false,
			'dismiss_notice_per_user'              => false,
			'import_form_action'                   => false,
			'template_licence_check'               => false,
			'captcha_setup_check'                  => false,
			'install_extension'                    => false,
			'profile_pic_remove'                   => false,
			'form_save_action'                     => false,
			'login_settings_save_action'           => false,
			'embed_form_action'                    => false,
			'embed_page_list'                      => false,
			'allow_usage_dismiss'                  => false,
			'cancel_email_change'                  => false,
			'email_setting_status'                 => false,
			'locked_form_fields_notice'            => false,
			'search_global_settings'               => false,
			'php_notice_dismiss'                   => false,
			'locate_form_action'                   => false,
			'form_preview_save'                    => false,
			'generate_row_settings'                => false,
			'my_account_selection_validator'       => false,
			'lost_password_selection_validator'    => false,
			'save_payment_settings'                => false,
			'disable_user'                         => false,
			'validate_payment_currency'            => false,
			'save_captcha_settings'                => false,
			'reset_captcha_keys'                   => false,
			'reset_payment_keys'                   => false,
			'create_default_form'                  => false,
			'generate_required_pages'              => false,
			'handle_default_wordpress_login'       => false,
			'enable_emails'                        => false,
			'skip_site_assistant_section'          => false,
			'login_settings_page_validation'       => false,
			'activate_dependent_module'            => false,
			'add_membership_field_to_default_form' => false,
			'update_state_field'                   => true,

		);

		foreach ( $ajax_events as $ajax_event => $nopriv ) {
			// Add nocache headers before each AJAX method
			add_action( 'wp_ajax_user_registration_' . $ajax_event, array( __CLASS__, 'add_nocache_headers' ), 5 );

			if ( $nopriv ) {
				add_action( 'wp_ajax_nopriv_user_registration_' . $ajax_event, array( __CLASS__, 'add_nocache_headers' ), 5 );
			}

			// Add the actual AJAX method
			add_action( 'wp_ajax_user_registration_' . $ajax_event, array( __CLASS__, $ajax_event ) );

			if ( $nopriv ) {
				add_action( 'wp_ajax_nopriv_user_registration_' . $ajax_event, array( __CLASS__, $ajax_event ) );
			}
		}
	}

	/**
	 * Add nocache headers to prevent caching interference with AJAX requests
	 */
	public static function add_nocache_headers() {
		nocache_headers();
	}

	/**
	 * Handle disable user ajax request.
	 *
	 * @since 4.3.0
	 */
	public static function disable_user() {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_send_json_error( array( 'message' => 'Sorry, you are not allowed to delete users.' ) );
		}

		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( $_GET['nonce'], 'bulk-users' ) ) { // phpcs:ignore
			wp_send_json_error( array( 'message' => 'Invalid nonce or unauthorized request.' ) );
		}
		$user_id        = isset( $_GET['user_id'] ) ? intval( $_GET['user_id'] ) : 0;
		$duration_value = isset( $_GET['duration_value'] ) ? intval( $_GET['duration_value'] ) : 0;
		$duration_unit  = isset( $_GET['duration_unit'] ) ? sanitize_text_field( wp_unslash( $_GET['duration_unit'] ) ) : '';

		if ( 0 === $user_id || 0 === $duration_value || '' === $duration_unit ) {
			wp_send_json_error( array( 'message' => 'Invalid time duration.' ) );
		}
		if ( isset( $_GET['action'] ) && 'user_registration_disable_user' === $_GET['action'] ) {

			switch ( $duration_unit ) {
				case 'days':
					$auto_enable_time = gmdate( 'Y-m-d H:i:s', strtotime( "+$duration_value days" ) );
					break;

				case 'weeks':
					$auto_enable_time = gmdate( 'Y-m-d H:i:s', strtotime( "+$duration_value weeks" ) );
					break;

				case 'months':
					$auto_enable_time = gmdate( 'Y-m-d H:i:s', strtotime( "+$duration_value months" ) );
					break;

				case 'years':
					$auto_enable_time = gmdate( 'Y-m-d H:i:s', strtotime( "+$duration_value years" ) );
					break;

				default:
					wp_send_json_error( array( 'message' => 'Invalid duration unit.' ) );

			}
			update_user_meta( $user_id, 'ur_disable_users', true );
			update_user_meta( $user_id, 'ur_auto_enable_time', $auto_enable_time );
			wp_send_json_success( array( 'message' => 'User disabled successfully.' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Invalid request.' ) );
		}
	}

	/**
	 * Triggered when admin search for the global settings.
	 */
	public static function search_global_settings() {
		check_ajax_referer( 'user_registration_search_global_settings', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}
		UR_Admin_Settings::search_settings();
	}

	/**
	 * Triggered when clicking the allow usage notice allow or deny buttons.
	 */
	public static function allow_usage_dismiss() {
		check_ajax_referer( 'allow_usage_nonce', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$allow_usage_tracking = isset( $_POST['allow_usage_tracking'] ) ? sanitize_text_field( wp_unslash( $_POST['allow_usage_tracking'] ) ) : false;

		update_option( 'user_registration_allow_usage_notice_shown', true );

		if ( ur_string_to_bool( $allow_usage_tracking ) ) {
			update_option( 'user_registration_allow_usage_tracking', true );
		} else {
			update_option( 'user_registration_allow_usage_tracking', false );
		}

		wp_die();
	}

	/**
	 * Get Post data on frontend form submit
	 *
	 * @return void
	 */
	public static function user_form_submit() {
		$nonce_value = isset( $_POST['ur_frontend_form_nonce'] ) ? wp_unslash( sanitize_key( $_POST['ur_frontend_form_nonce'] ) ) : '';
		ur_process_registration( $nonce_value );
	}


	/**
	 * Get Post data on frontend form submit
	 *
	 * @return void
	 */
	public static function update_profile_details() {

		if ( ! check_ajax_referer( 'user_registration_profile_details_save_nonce', 'security', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Nonce error, please reload.', 'user-registration' ),
				)
			);
		}

		// Current user id.
		$user_id = ! empty( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : get_current_user_id();

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to edit this user.', 'user-registration' ),
				)
			);
		}

		if ( $user_id <= 0 ) {
			return;
		}

		// Get form id of the form from which current user is registered.
		$form_id_array = get_user_meta( $user_id, 'ur_form_id' );
		$form_id       = 0;

		if ( isset( $form_id_array[0] ) ) {
			$form_id = $form_id_array[0];
		}

		// Make the schema of form data compatible with processing below.
		$form_data    = array();
		$single_field = array();

		if ( isset( $_POST['form_data'] ) ) {
			$form_data = json_decode( wp_unslash( $_POST['form_data'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( $form_data as $data ) {
				$single_field[ $data->field_name ] = isset( $data->value ) ? $data->value : '';
				$data->field_name                  = trim( str_replace( 'user_registration_', '', $data->field_name ) );
			}
		}

		$profile = user_registration_form_data( $user_id, $form_id );

		// No resolvable UR form (e.g. wp-admin/WooCommerce created user) - fall back to basic fields.
		if ( empty( $profile ) ) {
			$profile = ur_get_non_urm_user_profile_fields( $user_id );
		}

		if ( empty( $profile ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unable to update profile. No editable profile fields were found for this account.', 'user-registration' ),
				)
			);
		}
    
		// Server-derived, not client-supplied - avoids a spoofable $_POST flag.
		$is_admin_user                  = $user_id !== get_current_user_id();
		list( $profile, $single_field ) = urm_process_profile_fields( $profile, $single_field, $form_data, $form_id, $user_id, $is_admin_user );
		$user                           = get_userdata( $user_id );

		if ( 0 === ur_notice_count( 'error' ) ) {
			list( $email_updated, $pending_email ) = urm_update_user_profile_data( $user, $profile, $single_field, $form_id );

			/**
			 * Filter to modify the profile update success message.
			 */
			$message = apply_filters( 'user_registration_profile_update_success_message', __( 'User profile updated successfully.', 'user-registration' ) );
			/**
			 * Action to modify the save profile details.
			 *
			 * @param int $user_id The user ID.
			 * @param int $form_id The form ID.
			 */
			do_action( 'user_registration_save_profile_details', $user_id, $form_id );

			$profile_pic_id = get_user_meta( $user_id, 'user_registration_profile_pic_url' );
			$profile_pic_id = ! empty( $profile_pic_id ) ? $profile_pic_id[0] : '';
			$response       = array(
				'message'        => $message,
				'profile_pic_id' => $profile_pic_id,
				'email'          => ! empty( $single_field['user_registration_user_email'] ) ? $single_field['user_registration_user_email'] : '',
			);

			if ( $email_updated && ! $is_admin_user ) {
				UR_Form_Handler::send_confirmation_email( $user, $pending_email, $form_id );
				$response['oldUserEmail'] = $user->user_email;
				/* translators: %s : user email */
				$response['userEmailUpdateMessage'] = sprintf( __( 'Your email address has not been updated yet. Please check your inbox at <strong>%s</strong> for a confirmation email.', 'user-registration' ), $pending_email );

				$cancel_url = esc_url(
					add_query_arg(
						array(
							'cancel_email_change' => $user_id,
							'_wpnonce'            => wp_create_nonce( 'cancel_email_change_nonce' ),
						),
						ur_get_my_account_url() . get_option( 'user_registration_myaccount_edit_profile_endpoint', 'edit-profile' )
					)
				);

				$response['userEmailPendingMessage'] = sprintf(
				/* translators: %s - Email Change Pending Message. */
					'<div class="email-updated inline"><p>%s</p></div>',
					sprintf(
					/* translators: 1: Pending email message 2: Cancel Link */
						__( 'There is a pending change of your email to <code>%1$s</code>. <a href="%2$s">Cancel</a>', 'user-registration' ),
						$pending_email,
						$cancel_url
					)
				);
			}

			if ( $is_admin_user && ! empty( $pending_email ) ) {
				wp_update_user(
					array(
						'ID'         => $user_id,
						'user_email' => $pending_email,
					)
				);
			}
			/**
			 * Filter to modify profile update response.
			 *
			 * @param array $response The profile update response.
			 */
			$response = apply_filters( 'user_registration_profile_update_response', $response, $user_id );

			wp_send_json_success(
				$response
			);
		} else {
			$errors = ur_get_notices( 'error' );
			ur_clear_notices();
			wp_send_json_error(
				array(
					'message' => $errors,
				)
			);
		}
	}

	public static function unset_field( $field, $profile ) {
		$key = array_search( $field, $profile, true );
		if ( false !== ( $key ) ) {
			unset( $profile[ $key ] );
		}
	}

	/**
	 * Get Post data on frontend form submit
	 *
	 * @return void
	 */
	public static function profile_pic_upload() {

		check_ajax_referer( 'user_registration_profile_picture_upload_nonce', 'security' );

		$nonce = isset( $_REQUEST['security'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['security'] ) ) : false;

		$flag = wp_verify_nonce( $nonce, 'user_registration_profile_picture_upload_nonce' );

		if ( true != $flag || is_wp_error( $flag ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Nonce error, please reload.', 'user-registration' ),
				)
			);
		}

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		if ( isset( $_FILES['file']['size'] ) && wp_unslash( sanitize_key( $_FILES['file']['size'] ) ) ) {
			if ( ! function_exists( 'wp_handle_upload' ) ) {
				include_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$upload = isset( $_FILES['file'] ) ? $_FILES['file'] : array(); // phpcs:ignore

			// Sanitize filename immediately before any path operations.
			$src_file_name  = isset( $upload['name'] ) ? sanitize_file_name( wp_unslash( $upload['name'] ) ) : '';
			$file_extension = strtolower( pathinfo( $src_file_name, PATHINFO_EXTENSION ) );
			$file_mime_type = isset( $upload['tmp_name'] ) ? mime_content_type( $upload['tmp_name'] ) : '';

			// Valid extension for image.
			$valid_extensions = 'image/jpeg,image/gif,image/png';
			$form_id          = ur_get_form_id_by_userid( $user_id );

			if ( class_exists( 'UserRegistrationAdvancedFields' ) ) {
				$field_data       = ur_get_field_data_by_field_name( $form_id, 'profile_pic_url' );
				$valid_extensions = isset( $field_data['advance_setting']->valid_file_type ) ? implode( ', ', $field_data['advance_setting']->valid_file_type ) : $valid_extensions;
			}

			$valid_extension_type = explode( ',', $valid_extensions );
			$valid_ext            = array();

			foreach ( $valid_extension_type as $key => $value ) {
				$image_extension   = explode( '/', $value );
				$valid_ext[ $key ] = isset( $image_extension[1] ) ? $image_extension[1] : '';

				if ( 'jpeg' === $valid_ext[ $key ] ) {
					$index               = count( $valid_extension_type );
					$valid_ext[ $index ] = 'jpg';
				}
			}

			if ( ! in_array( $file_mime_type, $valid_extension_type ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid file type, please contact with site administrator.', 'user-registration' ),
					)
				);
			}

			// Validates if the uploaded file has the acceptable extension.
			if ( ! in_array( $file_extension, $valid_ext ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid file type, please contact with site administrator.', 'user-registration' ),
					)
				);
			}

			$upload_path = ur_get_tmp_dir();

			// Checks if the upload directory has the write permission.
			if ( ! wp_is_writable( $upload_path ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Upload path permission deny.', 'user-registration' ),
					)
				);
			}

			$upload_path = $upload_path . '/';

			// Use sanitized filename for wp_unique_filename to prevent path traversal.
			$file_name = wp_unique_filename( $upload_path, $src_file_name );
			$file_path = $upload_path . $file_name;
			// Ensure the resolved path is strictly within the intended upload directory.
			if ( strpos( realpath( dirname( $file_path ) ) . DIRECTORY_SEPARATOR, realpath( $upload_path ) . DIRECTORY_SEPARATOR ) !== 0 ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid file path.', 'user-registration' ),
					)
				);
			}

			if ( move_uploaded_file( $upload['tmp_name'], $file_path ) ) {
				$files = array(
					'file_name'      => $file_name,
					'file_path'      => $file_path,
					'file_extension' => $file_extension,
				);

				$attachment_id = wp_rand();

				ur_clean_tmp_files();
				$url = UR_UPLOAD_URL . 'temp-uploads/' . $file_name;
				wp_send_json_success(
					array(
						'attachment_id' => $attachment_id,
						'upload_files'  => crypt_the_string( maybe_serialize( $files ), 'e' ),
						'url'           => $url,
					)
				);
			} else {
				wp_send_json_error(
					array(
						'message' => __( 'File cannot be uploaded.', 'user-registration' ),
					)
				);
			}
		} elseif ( isset( $_FILES['file']['error'] ) && UPLOAD_ERR_NO_FILE !== $_FILES['file']['error'] ) {
			switch ( $_FILES['file']['error'] ) {
				case UPLOAD_ERR_INI_SIZE:
					wp_send_json_error(
						array(
							'message' => __( 'File size exceed, please check your file size.', 'user-registration' ),
						)
					);
					break;
				default:
					wp_send_json_error(
						array(
							'message' => __( 'Something went wrong while uploading, please contact your site administrator.', 'user-registration' ),
						)
					);
					break;
			}
		}
	}

	/**
	 * Login from Using Ajax
	 */
	public static function ajax_login_submit() {
		check_ajax_referer( 'ur_login_form_save_nonce', 'security' );

		$nonce = isset( $_REQUEST['security'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['security'] ) ) : false;
		$flag  = wp_verify_nonce( $nonce, 'ur_login_form_save_nonce' );

		if ( false === $flag || is_wp_error( $flag ) ) {

			wp_send_json_error(
				array(
					'message' => esc_html__( 'Nonce error, please reload.', 'user-registration' ),
				)
			);
		}

		ur_process_login( $nonce );
	}

	/**
	 * Send test email.
	 *
	 * @since 1.9.9
	 */
	public static function send_test_email() {
		check_ajax_referer( 'test_email_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to send test email.', 'user-registration' ) ) );
			wp_die( - 1 );
		}
		/**
		 * Filter to test mail from name.
		 * Default value is get_option('user_registration_email_from_name').
		 */
		$from_name = apply_filters( 'wp_mail_from_name', get_option( 'user_registration_email_from_name', esc_attr( get_bloginfo( 'name', 'display' ) ) ) );
		do_action( 'user_registration_email_send_before' );

		/**
		 * Filter to test mail from address.
		 * Default value is get_option('user_registration_email_from_address').
		 */
		$sender_email = apply_filters( 'wp_mail_from', get_option( 'user_registration_email_from_address', get_option( 'admin_email' ) ) );
		$email        = sanitize_email( isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		/* translators: %s - WP mail from name */
		$subject = 'User Registration & Membership: ' . sprintf( esc_html__( 'Test email from %s', 'user-registration' ), $from_name );
		$header  = array(
			'From:' . $from_name . ' <' . $sender_email . '>',
			'Reply-To:' . $sender_email,
			'Content-Type:text/html; charset=UTF-8',
		);
		$message =
			'Congratulations,<br>
		Your test email has been received successfully.<br>
		We thank you for trying out User Registration & Membership and joining our mission to make sure you get your emails delivered.<br>
		Regards,<br>
		User Registration & Membership Team';

		$status = wp_mail( $email, $subject, $message, $header );

		if ( $status ) {
			update_option( 'user_registration_successful_test_mail', true );
			wp_send_json_success( array( 'message' => __( 'Test email was sent successfully! Please check your inbox to make sure it is delivered.', 'user-registration' ) ) );
		} else {
			$error_message = apply_filters( 'user_registration_email_send_failed_message', '' );
			wp_send_json_error( array( 'message' => sprintf( __( 'Test email was unsuccessful!. %s', 'user-registration' ), $error_message ) ) );
		}
	}

	/**
	 * Locate form.
	 */
	public static function locate_form_action() {
		global $wpdb;
		try {
			check_ajax_referer( 'process-locate-ajax-nonce', 'security' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'user-registration' ) ) );
				wp_die( - 1 );
			}
			$id                          = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
			$user_registration_shortcode = '%[user_registration_form id="' . $id . '"%';
			$form_id_shortcode           = '%{"formId":"' . $id . '"%';
			$pages                       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}posts WHERE post_content LIKE %s OR post_content LIKE %s", $user_registration_shortcode, $form_id_shortcode ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$page_list                   = array();
			foreach ( $pages as $page ) {
				if ( '0' === $page->post_parent ) {
					$page_title               = $page->post_title;
					$page_guid                = $page->guid;
					$page_list[ $page_title ] = $page_guid;
				}
			}
			wp_send_json_success( $page_list );
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Get form settings theme styles
	 */
	public static function form_preview_save() {
		check_ajax_referer( 'ur_form_preview_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'user-registration' ) ) );
			wp_die( - 1 );
		}
		$form_id = isset( $_POST['id'] ) ? sanitize_text_field( $_POST['id'] ) : '';
		$theme   = isset( $_POST['theme'] ) ? sanitize_text_field( $_POST['theme'] ) : '';

		if ( empty( $form_id ) || empty( $theme ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient information', 'user-registration' ) ) );
		}

		$default_theme = ( 'default' === $theme ) ? 'default' : 'theme';
		update_post_meta( $form_id, 'user_registration_enable_theme_style', $default_theme );

		wp_send_json_success( array( 'message' => __( 'Saved', 'user-registration' ) ) );
	}

	/**
	 * User input dropped function
	 *
	 * @throws Exception Throws If Empty Form Data.
	 */
	public static function user_input_dropped() {

		try {
			check_ajax_referer( 'user_input_dropped_nonce', 'security' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'error' => __( 'You do not have permission.', 'user-registration' ) ) );
				wp_die( -1 );
			}

			$form_field_id = ( isset( $_POST['form_field_id'] ) ) ? sanitize_key( wp_unslash( $_POST['form_field_id'] ) ) : null;

			if ( null == $form_field_id || '' == $form_field_id ) {
				throw new Exception( 'Empty form data' );
			}

			$class_file_name = str_replace( 'user_registration_', '', $form_field_id );

			// Validate $class_file_name contains only safe characters (already enforced by sanitize_key, but double-check).
			if ( ! preg_match( '/^[a-z0-9_\-]+$/', $class_file_name ) ) {
				throw new Exception( 'Invalid form field identifier' );
			}

			$class_name      = ur_load_form_field_class( $class_file_name );

			if ( empty( $class_name ) ) {
				throw new Exception( 'class not exists' );
			}

			$templates = $class_name::get_instance()->get_admin_template();

			wp_send_json_success( $templates );
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'error' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Import Form ajax.
	 *
	 * @throws Exception Post data mot set.
	 */
	public static function import_form_action() {
		try {
			check_ajax_referer( 'ur_import_form_save_nonce', 'security' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'user-registration' ) ) );
				wp_die( - 1 );
			}
			UR_Admin_Import_Export_Forms::import_form();
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Form save from backend
	 *
	 * @return void
	 *
	 * @throws Exception Throw if any issue while saving form data.
	 */
	public static function form_save_action() {
		$logger  = ur_get_logger();
		$form_id = isset( $_POST['data']['form_id'] ) ? absint( $_POST['data']['form_id'] ) : 0;
		$start   = microtime( true );

		try {
			$logger->notice(
				sprintf( '[Form #%d] =============== ***Form save started*** ===============', $form_id ),
				array(
					'source'   => 'form-save',
					'form_id'  => $form_id,
					'function' => __FUNCTION__,
				)
			);

			$logger->info(
				sprintf( '[Form #%d] Function == ***%s()*** - Started execution', $form_id, __FUNCTION__ ),
				array(
					'source'  => 'form-save',
					'form_id' => $form_id,
				)
			);

			check_ajax_referer( 'ur_form_save_nonce', 'security' );

			if ( ! current_user_can( 'manage_options' ) ) {
				$logger->critical(
					sprintf( '[Form #%d] Permission check failed', $form_id ) . "\n" . 'Reason: User does not have permission to save forms',
					array(
						'source'   => 'form-save',
						'form_id'  => $form_id,
						'function' => __FUNCTION__,
					)
				);
				throw new Exception( __( "You don't have enough permission to perform this task. Please contact the Administrator.", 'user-registration' ) );
			}

			$logger->debug(
				sprintf( '[Form #%d] Security checks passed', $form_id ),
				array(
					'source'   => 'form-save',
					'form_id'  => $form_id,
					'function' => __FUNCTION__,
				)
			);

			if ( ! isset( $_POST['data'] ) || ! is_array( wp_unslash( $_POST['data'] ) ) ) { // phpcs:ignore
				$logger->critical(
					sprintf( '[Form #%d] Form payload validation failed', $form_id ) . "\n" . 'Reason: Post data is missing or invalid',
					array(
						'source'   => 'form-save',
						'form_id'  => $form_id,
						'function' => __FUNCTION__,
					)
				);
				throw new Exception( __( 'post data not set', 'user-registration' ) );
			}

			if ( ! isset( $_POST['data']['form_data'] ) || ! is_string( wp_unslash( $_POST['data']['form_data'] ) ) ) { // phpcs:ignore
				$logger->critical(
					sprintf( '[Form #%d] Form payload validation failed', $form_id ) . "\n" . 'Reason: Form data is missing or invalid',
					array(
						'source'   => 'form-save',
						'form_id'  => $form_id,
						'function' => __FUNCTION__,
					)
				);
				throw new Exception( __( 'post data not set', 'user-registration' ) );
			}

			$logger->debug(
				sprintf( '[Form #%d] Form payload validated', $form_id ),
				array(
					'source'   => 'form-save',
					'form_id'  => $form_id,
					'function' => __FUNCTION__,
				)
			);

			$post_data = json_decode( wp_unslash( $_POST['data']['form_data'] ) ); // phpcs:ignore
			self::sweep_array( $post_data );

			$logger->debug(
				sprintf( '[Form #%d] Form builder data decoded', $form_id ) . "\n" . wp_json_encode( $post_data, JSON_PRETTY_PRINT ),
				array(
					'source'   => 'form-save',
					'form_id'  => $form_id,
					'function' => __FUNCTION__,
				)
			);

			if ( isset( self::$failed_key_value['value'] ) && '' !== self::$failed_key_value['value'] ) {
				if ( in_array( self::$failed_key_value['value'], self::$field_key_aray, true ) ) {
					$logger->critical(
						sprintf( '[Form #%d] Duplicate field name detected', $form_id ) . "\n" . sprintf( 'Field: %s', self::$failed_key_value['value'] ),
						array(
							'source'   => 'form-save',
							'form_id'  => $form_id,
							'function' => __FUNCTION__,
						)
					);
					throw new Exception( sprintf( "Could not save form. Duplicate field name <span style='color:red'>%s</span>", self::$failed_key_value['value'] ) );
				}
			}

			if ( false === self::$is_field_key_pass ) {
				$logger->critical(
					sprintf( '[Form #%d] Invalid field name detected', $form_id ) . "\n" . 'Reason: One or more field names are invalid',
					array(
						'source'   => 'form-save',
						'form_id'  => $form_id,
						'function' => __FUNCTION__,
					)
				);
				throw new Exception( __( 'Could not save form. Invalid field name. Please check all field name', 'user-registration' ) );
			}

			$required_fields = array(
				'user_email',
				'user_pass',
			);

			if ( isset( $_POST['data']['form_setting_data'] ) ) {
				foreach ( wp_unslash( $_POST['data']['form_setting_data'] ) as $setting_data ) { // phpcs:ignore
					if ( 'user_registration_form_setting_enable_recaptcha_support' === $setting_data['name'] && ur_string_to_bool( $setting_data['value'] ) && ! ur_check_captch_keys( 'register', $_POST['data']['form_id'], true ) ) {
						$logger->critical(
							sprintf( '[Form #%d] Captcha configuration validation failed', $form_id ) . "\n" . 'Reason: Captcha is enabled but keys are missing',
							array(
								'source'   => 'form-save',
								'form_id'  => $form_id,
								'function' => __FUNCTION__,
							)
						);
						throw new Exception(
							sprintf(
							/* translators: %s - Integration tab url */
								'%s <a href="%s" class="ur-captcha-error" rel="noreferrer noopener" target="_blank">here</a> to add the captcha keys and save your changes.',
								esc_html__( 'Captcha setup is incomplete. Click', 'user-registration' ),
								esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=registration_login&section=captcha' ) )
							)
						); //phpcs:ignore
					}

					if ( 'user_registration_pro_auto_password_activate' === $setting_data['name'] && ur_string_to_bool( $setting_data['value'] ) ) {
						unset( $required_fields[ array_search( 'user_pass', $required_fields ) ] );
					}
				}
			}

			$logger->info(
				sprintf( '[Form #%d] Captcha configuration validated', $form_id ),
				array(
					'source'   => 'form-save',
					'form_id'  => $form_id,
					'function' => __FUNCTION__,
				)
			);

			$contains_search = count( array_intersect( $required_fields, self::$field_key_aray ) ) === count( $required_fields );

			if ( false === $contains_search ) {
				$logger->critical(
					sprintf( '[Form #%d] Required field validation failed', $form_id ) . "\n" . 'Missing fields: ' . implode( ', ', $required_fields ),
					array(
						'source'   => 'form-save',
						'form_id'  => $form_id,
						'function' => __FUNCTION__,
					)
				);
				throw new Exception( __( 'Could not save form, ' . join( ', ', $required_fields ) . ' fields are required.! ', 'user-registration' ) ); // phpcs:ignore
			}

			$logger->info(
				sprintf( '[Form #%d] Required fields validated', $form_id ),
				array(
					'source'   => 'form-save',
					'form_id'  => $form_id,
					'function' => __FUNCTION__,
				)
			);

			do_action( 'user_registration_admin_backend_validation_before_form_save' );

			$logger->debug(
				sprintf( '[Form #%d] Action == ***user_registration_admin_backend_validation_before_form_save*** - Triggered.', $form_id ),
				array(
					'source'  => 'form-save',
					'form_id' => $form_id,
				)
			);

			$form_name     = sanitize_text_field( $_POST['data']['form_name'] ); // phpcs:ignore
			$form_row_ids  = sanitize_text_field( $_POST['data']['form_row_ids'] ); // phpcs:ignore
			$form_id       = sanitize_text_field( $_POST['data']['form_id'] ); // phpcs:ignore
			$form_row_data = sanitize_text_field( $_POST['data']['row_data'] );

			$post_data = array(
				'post_type'      => 'user_registration',
				'post_title'     => sanitize_text_field( $form_name ),
				'post_content'   => wp_json_encode( $post_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			);

			if ( $form_id > 0 && is_numeric( $form_id ) ) {
				$post_data['ID'] = $form_id;
			}

			remove_filter( 'content_save_pre', 'wp_targeted_link_rel' );

			$post_id = wp_insert_post( wp_slash( $post_data ) );

			if ( $post_id > 0 ) {
				$_POST['data']['form_id'] = $post_id; // phpcs:ignore

				$post_data_setting = isset( $_POST['data']['form_setting_data'] ) ? $_POST['data']['form_setting_data'] : array(); // phpcs:ignore

				if ( isset( $_POST['data']['form_restriction_submit_data'] ) && ! empty( $_POST['data']['form_restriction_submit_data'] ) ) {
					array_push(
						$post_data_setting,
						array(
							'name'  => 'urfr_qna_restriction_data',
							'value' => sanitize_text_field( wp_unslash( $_POST['data']['form_restriction_submit_data'] ) ),
						)
					);
				}

				ur_update_form_settings( $post_data_setting, $post_id );

				// Form row_id save.
				update_post_meta( $form_id, 'user_registration_form_row_ids', $form_row_ids );

				// Form row_data save.
				update_post_meta( $form_id, 'user_registration_form_row_data', $form_row_data );
			}

			/**
			 * Action after form setting save.
			 * Default is the $_POST['data'].
			 */
			do_action( 'user_registration_after_form_settings_save', wp_unslash( $_POST['data'] ) ); // phpcs:ignore

			$logger->debug(
				sprintf( '[Form #%d] Action == ***user_registration_after_form_settings_save*** - Triggered.', $form_id ),
				array(
					'source'  => 'form-save',
					'form_id' => $form_id,
				)
			);

			$duration = round( microtime( true ) - $start, 2 );

			$logger->notice(
				sprintf( '[Form #%d] Form saved successfully', $post_id ? $post_id : absint( $form_id ) ) . "\n" . wp_json_encode(
					$post_data,
					JSON_PRETTY_PRINT
				),
				array(
					'source'   => 'form-save',
					'form_id'  => $post_id ? $post_id : absint( $form_id ),
					'function' => __FUNCTION__,
					'post_id'  => $post_id,
				)
			);

			$logger->success(
				sprintf( '[Form #%d]  =============== ***Form save completed*** ===============', $post_id ? $post_id : absint( $form_id ) ) . "\n  ",
				array(
					'source'   => 'form-save',
					'form_id'  => $post_id ? $post_id : absint( $form_id ),
					'function' => __FUNCTION__,
					'post_id'  => $post_id,
				)
			);

			wp_send_json_success(
				array(
					'data'    => $post_data,
					'post_id' => $post_id,
				)
			);
		} catch ( Exception $e ) {
			$logger->error(
				sprintf( '[Form #%d] Form save failed', $form_id ) . "\n" . sprintf( 'Reason: %s', $e->getMessage() ) . "\n  ",
				array(
					'source'   => 'form-save',
					'form_id'  => $form_id,
					'function' => __FUNCTION__,
				)
			);

			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	public static function login_settings_save_action() {

		check_ajax_referer( 'ur_login_settings_save_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'user-registration' ) ) );
		}

		$settings_data = $_POST['data']['setting_data'];

		$settings_data = array_values(
			array_filter(
				$settings_data,
				function ( $item ) {
					return isset( $item['option'] ) && $item['option'] !== 'user_registration_form_setting_general_advanced';
				}
			)
		);

		$output = array();
		foreach ( $settings_data as $item ) {
			if ( isset( $item['option'] ) ) {
				$output[ $item['option'] ] = isset( $item['value'] ) ? $item['value'] : '';
			}
		}

		do_action( 'user_registration_validation_before_login_form_save', $output );

		if ( ur_string_to_bool( $output['user_registration_login_options_enable_recaptcha'] ) ) {
			if ( '' === $output['user_registration_login_options_configured_captcha_type'] || ! $output['user_registration_login_options_configured_captcha_type'] ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( "Seems like you haven't selected the reCAPTCHA type (Configured Captcha).", 'user-registration' ),
					)
				);
			}
		}

		if ( ur_string_to_bool( $output['user_registration_login_options_prevent_core_login'] ) ) {

			$login_redirect_value = isset( $output['user_registration_login_options_login_redirect_url'] ) ? $output['user_registration_login_options_login_redirect_url'] : '';
			if ( empty( $login_redirect_value ) || ! is_numeric( $login_redirect_value ) ) {
				$login_redirect_value = get_option( 'user_registration_login_options_login_redirect_url', '' );
			}
			$login_redirect_page_id = absint( $login_redirect_value );

			if ( $login_redirect_page_id > 0 ) {
				$is_page_my_account_page = ur_find_my_account_in_page( $login_redirect_page_id );
				if ( ! $is_page_my_account_page ) {
					wp_send_json_error(
						array(
							'message' => esc_html__(
								'The selected page is not a User Registration & Membership Login or My Account page.',
								'user-registration'
							),
						)
					);
				}
			} else {
				wp_send_json_error(
					array(
						'message' => esc_html__(
							'Please select a login redirection page.',
							'user-registration'
						),
					)
				);
			}
		}

		// check for valid lost password and reset password page.
		if ( ur_string_to_bool( $output['user_registration_login_options_lost_password'] ) ) {

			if ( ! empty( $output['user_registration_lost_password_page_id'] ) && ( is_numeric( $output['user_registration_lost_password_page_id'] ) ) ) {
				$is_page_lost_password_page = ur_find_lost_password_in_page( sanitize_text_field( wp_unslash( $output['user_registration_lost_password_page_id'] ) ) );
				if ( ! $is_page_lost_password_page ) {
					wp_send_json_error(
						array(
							'message' => esc_html__(
								'The selected page does not contain the required lost password shortcode [user_registration_lost_password]',
								'user-registration'
							),
						)
					);
				}
			} else {
				wp_send_json_error(
					array(
						'message' => esc_html__(
							'Please select a valid lost password page.',
							'user-registration'
						),
					)
				);
			}
		}

		foreach ( $output as $key => $settings ) {
			if ( 'user_registration_login_options_login_redirect_url' === $key ) {
				if ( ! is_numeric( $settings ) || empty( $settings ) ) {
					$settings = get_option( 'user_registration_login_options_login_redirect_url', '' );
				}
				if ( is_numeric( $settings ) && ! empty( $settings ) ) {
					update_option( 'user_registration_login_page_id', $settings );
				}
			}
			if ( 'user_registration_login_page_id' === $key ) {
				if ( ! is_numeric( $settings ) || empty( $settings ) ) {
					$settings = get_option( 'user_registration_login_page_id', '' );
				}
				if ( is_numeric( $settings ) && ! empty( $settings ) ) {
					update_option( 'user_registration_login_options_login_redirect_url', $settings );
				}
			}
			update_option( $key, $settings );
		}

		/**
		 * Action after form setting save.
		 * Default is the $_POST['data'].
		 */
		do_action( 'user_registration_after_login_form_settings_save', wp_unslash( $settings_data ) ); //phpcs:ignore

		wp_send_json_success(
			array()
		);
	}

	/**
	 * Get all pages for embed form form builder to page.
	 *
	 * @since 4.3.0
	 */
	public static function embed_page_list() {
		check_ajax_referer( 'ur_embed_page_list_nonce', 'security' );
		$args  = array(
			'post_status' => 'publish',
			'post_type'   => 'page',
		);
		$pages = get_pages( $args );
		wp_send_json_success( $pages );
	}

	/**
	 * Embed form action ajax.
	 *
	 * @return void
	 */
	public static function embed_form_action() {
		check_ajax_referer( 'ur_embed_action_nonce', 'security' );
		$page_id  = empty( $_POST['page_id'] ) ? 0 : sanitize_text_field( absint( $_POST['page_id'] ) );
		$form_id  = ! empty( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$is_login = ! empty( $_POST['is_login'] ) ? sanitize_text_field( wp_unslash( $_POST['is_login'] ) ) : 'no';

		if ( empty( $page_id ) ) {

			if ( ! current_user_can( 'publish_pages' ) ) {
				wp_send_json_error( __( 'You do not have permission to create pages.', 'user-registration' ) );
			}

			$url              = add_query_arg( 'post_type', 'page', admin_url( 'post-new.php' ) );
			$meta             = array(
				'embed_page'       => 0,
				'embed_page_title' => ! empty( $_POST['page_title'] ) ? sanitize_text_field( wp_unslash( $_POST['page_title'] ) ) : '',
			);
			$page_url         = add_query_arg(
				array(
					'form' => 'user_registration',
				),
				esc_url_raw( $url )
			);
			$meta['form_id']  = $form_id;
			$meta['is_login'] = $is_login;

			UR_Admin_Embed_Wizard::set_meta( $meta );
			wp_send_json_success( $page_url );
		} else {

			if ( ! current_user_can( 'edit_post', $page_id ) ) {
						wp_send_json_error( __( 'You do not have permission to edit this page.', 'user-registration' ) );
			}

			UR_Admin_Embed_Wizard::delete_meta();
			$url  = get_edit_post_link( $page_id, '' );
			$post = get_post( $page_id );

			if ( ur_string_to_bool( $is_login ) ) {
				$shortcode = '[user_registration_login]';
			} else {
				$pattern   = '[user_registration_form id="%d"]';
				$shortcode = sprintf( $pattern, absint( $form_id ) );
			}
			$updated_content = $post->post_content . "\n\n" . $shortcode;
			$id              = wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => $updated_content,
				)
			);
			if ( $is_login ) {
				update_option( 'user_registration_login_page_id', $id );
				update_option( 'user_registration_login_options_login_redirect_url', $id );
			}
			wp_send_json_success( $url );
		}
	}

	/**
	 * Dashboard Widget data.
	 *
	 * @since 1.5.8
	 */
	public static function dashboard_widget() {

		check_ajax_referer( 'dashboard-widget', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'user-registration' ) ) );
			wp_die( - 1 );
		}

		$form_id = isset( $_POST['form_id'] ) ? wp_unslash( absint( $_POST['form_id'] ) ) : 0;

		$user_report = $form_id ? ur_get_user_report( $form_id ) : array();
		$forms       = ! $form_id ? ur_get_all_user_registration_form() : array();

		wp_send_json(
			array(
				'user_report' => $user_report,
				'forms'       => $forms,
			)
		); // WPCS: XSS OK.
	}

	/**
	 * Checks if the string passes the regex
	 *
	 * @param string $value Value.
	 *
	 * @return boolean
	 */
	private static function is_regex_pass( $value ) {

		$field_regex = "/[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*/";

		if ( preg_match( $field_regex, $value, $match ) ) :
			if ( null !== $match && count( $match ) == 1 && $match[0] === $value ) {
				return true;
			}
		endif;

		return false;
	}

	/**
	 * Sanitize values of form field in backend
	 *
	 * @param array $array Array.
	 */
	public static function sweep_array( &$array ) {

		foreach ( $array as $key => &$value ) {
			if ( 'field_key' === $key ) {
				$field_key = $value;
			}

			if ( isset( $field_key ) && 'checkbox' === $field_key ) {
				if ( gettype( $value ) == 'object' ) {
					if ( isset( $value->options ) && is_array( $value->options ) && ! empty( $value->options ) ) {
						$sanitized_options = array();
						$allowed_tags      = array(
							array(),
							'a' => array(
								'href'      => true,
								'title'     => true,
								'target'    => true,
								'download'  => true,
								'rel'       => true,
								'hreflang'  => true,
								'type'      => true,
								'name'      => true,
								'accesskey' => true,
								'tabindex'  => true,
							),
						);

						foreach ( $value->options as $index => $option_value ) {
							$option_value = str_replace( '"', "'", $option_value );
							$option_value = wp_kses( trim( $option_value ), $allowed_tags );

							// Check if the option_value contains an open <a> tag but not a closing </a> tag.
							if ( preg_match( '/<a\s[^>]*>/i', $option_value ) && ! preg_match( '/<\/a>/i', $option_value ) ) {
								// Add a closing </a> tag to the end of the option_value.
								$option_value .= '</a>';
							}

							$sanitized_options [] = $option_value;
						}

						$value->options = $sanitized_options;
					}
				}
			} elseif ( is_array( $value ) || gettype( $value ) === 'object' ) {
				if ( isset( $value->field_key ) ) {
					/**
					 * Filter to modify the field settings.
					 *
					 * The dynamic portion of the hook name, $value->field_key.
					 *
					 * @param array $value The field value.
					 */
					$value = apply_filters( 'user_registration_field_setting_' . $value->field_key, $value );
				}
				self::sweep_array( $value );
			} else {
				if ( 'field_name' === $key ) {
					$regex_status = self::is_regex_pass( $value );

					if ( ! $regex_status || in_array( $value, self::$field_key_aray ) ) {
						self::$is_field_key_pass = false;
						self::$failed_key_value  = array(
							'key'   => $key,
							'value' => $value,
						);

						return;
					}
					array_push( self::$field_key_aray, $value );
				}
				if ( 'description' === $key ) {
					$value = str_replace( '"', "'", $value );
					$value = wp_kses(
						$value,
						array(
							'a'          => array(
								'href'   => array(),
								'title'  => array(),
								'target' => array(),
							),
							'br'         => array(),
							'em'         => array(),
							'strong'     => array(),
							'u'          => array(),
							'i'          => array(),
							'q'          => array(),
							'b'          => array(),
							'ul'         => array(),
							'ol'         => array(),
							'li'         => array(),
							'hr'         => array(),
							'blockquote' => array(),
							'del'        => array(),
							'strike'     => array(),
							'code'       => array(),
							'div'        => array(),
							'span'       => array(),
							'p'          => array(),
							'h1'         => array(),
							'h2'         => array(),
							'h3'         => array(),
							'h4'         => array(),
							'h5'         => array(),
							'h6'         => array(),
						)
					);
				} elseif ( 'html' === $key ) {
					if ( ! current_user_can( 'unfiltered_html' ) ) {
						$value = wp_kses_post( $value );
					}
				} else {
					$value = sanitize_text_field( $value );
				}
			}
		}
	}

	/**
	 * Triggered when clicking the rating footer.
	 *
	 * @since 1.1.2
	 */
	public static function rated() {
		if ( ! current_user_can( 'manage_user_registration' ) ) {
			wp_die( - 1 );
		}
		update_option( 'user_registration_admin_footer_text_rated', 1 );
		wp_die();
	}

	/**
	 * Dismiss user registration notices.
	 *
	 * @return void
	 * *@since 1.5.8
	 */
	public static function dismiss_notice() {
		$notice_id   = isset( $_POST['notice_id'] ) ? wp_unslash( sanitize_key( $_POST['notice_id'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification
		$notice_type = isset( $_POST['notice_type'] ) ? wp_unslash( sanitize_key( $_POST['notice_type'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( $notice_type . '-nonce', 'security' );
		if ( ! empty( $_POST['dismissed'] ) ) {
			if ( ! empty( $_POST['dismiss_forever'] ) && ur_string_to_bool( sanitize_text_field( wp_unslash( $_POST['dismiss_forever'] ) ) ) ) {
				update_option( 'user_registration_' . $notice_id . '_notice_dismissed', true );
			} else {
				$notice_dismissed_temporarily = json_decode( get_option( 'user_registration_' . $notice_id . '_notice_dismissed_temporarily', '' ), true );
				$reopen_times                 = isset( $notice_dismissed_temporarily ) ? $notice_dismissed_temporarily['reopen_times'] : 0;

				$notice_data = array(
					'last_dismiss' => current_time( 'Y-m-d' ),
					'reopen_times' => $reopen_times + 1,
				);
				update_option( 'user_registration_' . $notice_id . '_notice_dismissed_temporarily', json_encode( $notice_data ) );
			}

			// Never display mail send failed notice once dismissed.
			if ( 'info_ur_email_send_failed' === $notice_id ) {
				delete_transient( 'user_registration_mail_send_failed_count' );
			}
		}
	}
	/**
	 * Dismiss user registration notice per user.
	 */
	public static function dismiss_notice_per_user() {
		$notice_id   = isset( $_POST['notice_id'] ) ? wp_unslash( sanitize_key( $_POST['notice_id'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification
		$notice_type = isset( $_POST['notice_type'] ) ? wp_unslash( sanitize_key( $_POST['notice_type'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( $notice_type . '-nonce', 'security' );

		$user_id               = get_current_user_id();
		$urm_dismissed_notices = get_user_meta( $user_id, 'urm_dismissed_notices', true );
		// if not an array, make it an array.
		if ( ! is_array( $urm_dismissed_notices ) ) {
			$urm_dismissed_notices = array();
		}

		switch ( $notice_id ) {
			case 'non_urm_users_notice':
				[ 'dismiss_count' => $dismiss_count, 'last_dismissed_at' => $last_dismissed_at ] = isset( $urm_dismissed_notices['non_urm_users_notice'] ) ? $urm_dismissed_notices['non_urm_users_notice'] : array(
					'dismiss_count' => 0,
					current_time( 'timestamp' ) - 3 * DAY_IN_SECONDS,
				);
				$dismiss_count                                 = current_time( 'timestamp' ) - $last_dismissed_at <= 48 * HOUR_IN_SECONDS ? $dismiss_count + 1 : $dismiss_count;
				$urm_dismissed_notices['non_urm_users_notice'] = array(
					'dismiss_count'     => $dismiss_count,
					'last_dismissed_at' => current_time( 'timestamp' ),
				);
				break;
			default:
				break;
		}
		update_user_meta( $user_id, 'urm_dismissed_notices', $urm_dismissed_notices );

		wp_send_json_success();
	}
	/**
	 * Remove profile picture ajax method.
	 */
	public static function profile_pic_remove() {
		check_ajax_referer( 'user_registration_profile_picture_remove_nonce', 'security' );
		$nonce = isset( $_REQUEST['security'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['security'] ) ) : false;

		$flag = wp_verify_nonce( $nonce, 'user_registration_profile_picture_remove_nonce' );

		if ( true != $flag || is_wp_error( $flag ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Nonce error, please reload.', 'user-registration' ),
				)
			);
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? intval( wp_unslash( $_POST['attachment_id'] ) ) : '';

		if ( is_user_logged_in() ) {
			$user_id             = get_current_user_id();
			$user_profile_pic_id = get_user_meta( $user_id, 'user_registration_profile_pic_url' );

			if ( $user_profile_pic_id == $attachment_id ) {

				if ( file_exists( get_attached_file( $attachment_id ) ) && ! unlink( get_attached_file( $attachment_id ) ) ) {
					wp_send_json_error(
						array(
							'message' => esc_html__( 'File cannot be removed', 'user-registration' ),
						)
					);
				}
				update_user_meta( $user_id, 'user_registration_profile_pic_url', '' );
			} else {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'File cannot be removed', 'user-registration' ),
					)
				);
			}
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'File cannot be removed', 'user-registration' ),
				)
			);

		}

		wp_send_json_success(
			array(
				'message' => __( 'User profile picture removed successfully', 'user-registration' ),
			)
		);
	}

	/**
	 * Ajax handler for licence check.
	 *
	 * @global WP_Filesystem_Base $wp_filesystem Subclass
	 */
	public static function template_licence_check() {
		check_ajax_referer( 'user_registration_template_licence_check', 'security' );

		if ( empty( $_POST['plan'] ) ) {
			wp_send_json_error(
				array(
					'plan'         => '',
					'errorCode'    => 'no_plan_specified',
					'errorMessage' => esc_html__( 'No Plan specified.', 'user-registration' ),
				)
			);
		}

		$addons        = array();
		$template_data = UR_Admin_Form_Templates::get_template_data();
		$template_data = is_array( $template_data ) ? $template_data : array();
		if ( ! empty( $template_data ) ) {
			foreach ( $template_data as $template ) {
				if ( isset( $_POST['slug'] ) && $template->slug === $_POST['slug'] && in_array( trim( $_POST['plan'] ), $template->plan, true ) ) {
					$addons = $template->addons;
				}
			}
		}

		$output  = '<div class="user-registration-recommend-addons">';
		$output .= '<h3>' . esc_html__( 'This form template requires the following addons.', 'user-registration' ) . '</h3>';
		$output .= '<table class="plugins-list-table widefat striped">';
		$output .= '<thead><tr><th scope="col" class="manage-column required-plugins" colspan="2">' . esc_html__( 'Required Addons', 'user-registration' ) . '</th></tr></thead><tbody id="the-list">';
		$output .= '</div>';

		$activated = true;

		foreach ( $addons as $slug => $addon ) {
			$plugin = 'user-registration-pro' === $slug ? $slug . '/user-registration.php' : $slug . '/' . $slug . '.php';

			if ( is_plugin_active( $plugin ) ) {
				$class        = 'active';
				$parent_class = '';
			} elseif ( file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
				$class        = 'activate-now';
				$parent_class = 'inactive';
				$activated    = false;
			} else {
				$class        = 'install-now';
				$parent_class = 'inactive';
				$activated    = false;
			}

			$output .= '<tr class="plugin-card-' . $slug . ' plugin ' . $parent_class . '" data-slug="' . $slug . '" data-plugin="' . $plugin . '" data-name="' . $addon . '">';
			$output .= '<td class="plugin-name">' . $addon . '</td>';
			$output .= '<td class="plugin-status"><span class="' . esc_attr( $class ) . '"></span></td>';
			$output .= '</tr>';
		}
		$output .= '</tbody></table></div>';

		wp_send_json_success(
			array(
				'html'     => $output,
				'activate' => $activated,
			)
		);
	}

	/**
	 * Check for captcha setup.
	 */
	public static function captcha_setup_check() {
		check_ajax_referer( 'user_registration_captcha_setup_check', 'security' );

		if ( ur_check_captch_keys() ) {
			wp_send_json_success(
				array(
					'is_captcha_setup' => true,
				)
			);
		}

		wp_send_json_error(
			array(
				'is_captcha_setup'        => false,
				'captcha_setup_error_msg' => sprintf(
				/* translators: %s - Integration tab url */
					__( 'Seems like you haven\'t added the reCAPTCHA Keys. <a href="%s" >Add Now.</a>', 'user-registration' ),
					esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=captcha' ) )
				),
			)
		);
	}

	/**
	 * Ajax handler for installing a extension.
	 *
	 * @since 1.2.0
	 *
	 * @see Plugin_Upgrader
	 *
	 * @global WP_Filesystem_Base $wp_filesystem Subclass
	 */
	public static function install_extension() {

		check_ajax_referer( 'updates' );

		if ( empty( $_POST['slug'] ) ) {
			wp_send_json_error(
				array(
					'slug'         => '',
					'errorCode'    => 'no_plugin_specified',
					'errorMessage' => esc_html__( 'No plugin specified.', 'user-registration' ),
				)
			);
		}

		$slug        = sanitize_key( wp_unslash( $_POST['slug'] ) );
		$plugin_slug = 'user-registration-pro' === $slug ? wp_unslash( $_POST['slug'] . '/user-registration.php' ) : wp_unslash( $_POST['slug'] . '/' . $_POST['slug'] . '.php' ); // phpcs:ignore
		$plugin      = plugin_basename( sanitize_text_field( $plugin_slug ) );
		$status      = array(
			'install' => 'plugin',
			'slug'    => sanitize_key( wp_unslash( $_POST['slug'] ) ),
		);

		if ( ! current_user_can( 'install_plugins' ) ) {
			$status['errorMessage'] = esc_html__( 'Sorry, you are not allowed to install plugins on this site.', 'user-registration' );
			wp_send_json_error( $status );
		}

		include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		if ( file_exists( WP_PLUGIN_DIR . '/' . $slug ) ) {
			$plugin_data          = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
			$status['plugin']     = $plugin;
			$status['pluginName'] = $plugin_data['Name'];

			if ( current_user_can( 'activate_plugin', $plugin ) && is_plugin_inactive( $plugin ) ) {
				$result = activate_plugin( $plugin );

				if ( is_wp_error( $result ) ) {
					$status['errorCode']    = $result->get_error_code();
					$status['errorMessage'] = $result->get_error_message();
					wp_send_json_error( $status );
				}

				wp_send_json_success( $status );
			}
		}

		$api_params = array(
			'license'   => get_option( 'user-registration_license_key' ),
			'item_name' => ! empty( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
		);

		if ( 'user-registration-pro' == $slug ) {
			$api_params['item_id'] = 167196;
		}

		$api = json_decode(
			UR_Updater_Key_API::version(
				$api_params
			)
		);

		if ( is_wp_error( $api ) ) {
			$status['errorMessage'] = $api->get_error_message();
			wp_send_json_error( $status );
		}

		$status['pluginName'] = $api->name;

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$status['debug'] = $skin->get_upgrade_messages();
		}

		if ( is_wp_error( $result ) ) {
			$status['errorCode']    = $result->get_error_code();
			$status['errorMessage'] = $result->get_error_message();
			wp_send_json_error( $status );
		} elseif ( is_wp_error( $skin->result ) ) {
			$status['errorCode']    = $skin->result->get_error_code();
			$status['errorMessage'] = $skin->result->get_error_message();
			wp_send_json_error( $status );
		} elseif ( $skin->get_errors()->get_error_code() ) {
			$status['errorMessage'] = $skin->get_error_messages();
			wp_send_json_error( $status );
		} elseif ( is_null( $result ) ) {
			global $wp_filesystem;

			$status['errorCode']    = 'unable_to_connect_to_filesystem';
			$status['errorMessage'] = esc_html__( 'Unable to connect to the filesystem. Please confirm your credentials.', 'user-registration' );

			// Pass through the error from WP_Filesystem if one was raised.
			if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->get_error_code() ) {
				$status['errorMessage'] = esc_html( $wp_filesystem->errors->get_error_message() );
			}

			wp_send_json_error( $status );
		}

		$api->version   = isset( $api->new_version ) ? $api->new_version : '';
		$install_status = install_plugin_install_status( $api );

		if ( current_user_can( 'activate_plugin', $install_status['file'] ) && is_plugin_inactive( $install_status['file'] ) ) {
			$current_page        = isset( $_POST['page'] ) ? sanitize_text_field( wp_unslash( $_POST['page'] ) ) : '';
			$auto_activate_pages = array(
				'user-registration-membership_page_add-new-registration',
				'user-registration-membership_page_user-registration-settings',
			);
			if ( in_array( $current_page, $auto_activate_pages, true ) ) {
				activate_plugin( $install_status['file'] );
			} else {
				$status['activateUrl'] =
					esc_url_raw(
						add_query_arg(
							array(
								'action'   => 'activate',
								'plugin'   => $install_status['file'],
								'_wpnonce' => wp_create_nonce( 'activate-plugin_' . $install_status['file'] ),
							),
							admin_url( 'admin.php?page=user-registration-addons' )
						)
					);
			}
		}

		wp_send_json_success( $status );
	}

	/**
	 * AJAX create new form.
	 */
	public static function create_form() {
		ob_start();

		check_ajax_referer( 'user_registration_create_form', 'security' );

		$all_forms = ur_get_all_user_registration_form();

		if ( ( ! empty( $all_forms ) && count( $all_forms ) <= 1 && ! ur_check_module_activation( 'multiple-registration' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Multiple registration forms cannot be created.', 'user-registration' ) ) );
			die( -1 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create form.', 'user-registration' ) ) );
			wp_die( - 1 );
		}

		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : esc_html__( 'Blank Form', 'user-registration' );
		$template = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : 'blank';

		$form_id = UR()->form->create( $title, $template );

		if ( $form_id ) {
			$data = array(
				'id'       => $form_id,
				'redirect' => add_query_arg(
					array(
						'tab'     => 'fields',
						'form_id' => $form_id,
					),
					admin_url( 'admin.php?page=add-new-registration&edit-registration=' . $form_id )
				),
			);

			wp_send_json_success( $data );
		}

		wp_send_json_error(
			array(
				'error' => esc_html__( 'Something went wrong, please try again later', 'user-registration' ),
			)
		);
	}

	/**
	 * Cancel a pending email change.
	 *
	 * @return void
	 */
	public static function cancel_email_change() {
		check_ajax_referer( 'cancel_email_change_nonce', '_wpnonce' );

		$user_id = isset( $_POST['cancel_email_change'] ) ? absint( wp_unslash( $_POST['cancel_email_change'] ) ) : false;

		if ( ! $user_id ) {
			wp_die( - 1 );
		}

		if ( $user_id !== get_current_user_id() && ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( - 1 );
		}

		// Remove the confirmation key, pending email and expiry date.
		UR_Form_Handler::delete_pending_email_change( $user_id );

		wp_send_json_success(
			array(
				'message' => __( 'Changed email cancelled successfully.', 'user-registration' ),
			)
		);
	}

	/**
	 * Email setting status
	 */
	public static function email_setting_status() {
		$security = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		if ( '' === $security || ! wp_verify_nonce( $security, 'email_setting_status_nonce' ) ) {
			wp_send_json_error( 'Nonce verification failed' );

			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permision Denied' );

			return;
		}
		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : null;
		$id     = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : null;
		$value  = ur_string_to_bool( $status );
		$key    = 'user_registration_enable_' . $id;

		$option = get_option( $key, 'NO_OPTION' );
		if ( 'NO_OPTION' === $option ) {
			$status = add_option( $key, $value );
		} else {

			$status = update_option( $key, $value );
		}
		if ( $status ) {
			wp_send_json_success( 'Successfully Updated' );
		} else {
			wp_send_json_error( 'Update failed !' );
		}
	}

	/**
	 * Install or upgrade to premium.
	 */
	public static function locked_form_fields_notice() {
		$security = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		if ( '' === $security || ! wp_verify_nonce( $security, 'locked_form_fields_notice_nonce' ) ) {
			wp_send_json_error( 'Nonce verification failed' );

			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permision Denied' );

			return;
		}
		$plan         = isset( $_POST['plan'] ) ? sanitize_text_field( wp_unslash( $_POST['plan'] ) ) : null;
		$slug         = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : null;
		$name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : null;
		$video_id     = isset( $_POST['video_id'] ) ? sanitize_text_field( wp_unslash( $_POST['video_id'] ) ) : null;
		$license_data = ur_get_license_plan();
		$button       = '';

		if ( false === $license_data ) {

			if ( is_plugin_active( 'user-registration-pro/user-registration.php' ) ) {
				$button = '<div class="action-buttons"><a class="button activate-license-now" href="' . esc_url( admin_url( 'admin.php?page=user-registration-settings&tab=license' ) ) . '" rel="noreferrer noopener" target="_blank">' . esc_html__( 'Activate License', 'user-registration' ) . '</a></div>';
				wp_send_json_success( array( 'action_button' => $button ) );
			} else {
				$button = '<div class="action-buttons"><a class="button upgrade-now" href="' . esc_url( ur_utm_url( 'https://wpuserregistration.com/upgrade/', array( 'source' => 'builder-fields', 'medium' => 'popup' ) ) ) . '" rel="noreferrer noopener" target="_blank">' . esc_html__( 'Upgrade Plan', 'user-registration' ) . '</a></div>';
				wp_send_json_success( array( 'action_button' => $button ) );
			}
		}
		$license_plan = ! empty( $license_data->item_plan ) ? $license_data->item_plan : false;

		$license_plan = $license_plan . ' plan';
		$license_plan = trim( $license_plan );

		if ( 'themegrill agency plan' === $license_plan || 'professional plan' === $license_plan || 'plus plan' === $license_plan ) {
			$license_plan = 'themegrill agency plan or professional plan or plus plan';
		}
		if ( strtolower( $plan ) === $license_plan ) {
			if ( 'themegrill agency plan or professional plan or plus plan' === $license_plan ) {
				$plan_list = array( 'plus', 'professional', 'personal', 'themegrill agency' );
			} else {
				$plan_list = array( 'personal' );
			}
		} elseif ( strtolower( $plan ) === 'personal plan' && 'themegrill agency plan or professional plan or plus plan' === $license_plan ) {
			$plan_list = array( 'plus', 'professional', 'personal', 'themegrill agency' );
		} else {
			$plan_list = array();
		}
		if ( $plan ) {
			$addon = (object) array(
				'title' => '',
				'slug'  => $slug,
				'name'  => $name,
				'plan'  => $plan_list,
			);
		}

		ob_start();
		/**
		 * Action after addon description.
		 *
		 * @param array $addon The addon's details.
		 */
		do_action( 'user_registration_after_addons_description', $addon );
		$button = ob_get_clean();
		wp_send_json_success( array( 'action_button' => $button ) );
	}


	/**
	 * Handle PHP Deprecated notice dismiss action.
	 *
	 * @return bool
	 */
	public static function php_notice_dismiss() {
		$current_date = gmdate( 'Y-m-d' );
		$prompt_count = get_option( 'user_registration_php_deprecated_notice_prompt_count', 0 );

		update_option( 'user_registration_php_deprecated_notice_last_prompt_date', $current_date );
		update_option( 'user_registration_php_deprecated_notice_prompt_count', ++$prompt_count );

		return false;
	}

	/**
	 * Handle Row settings generation.
	 *
	 * @return bool
	 */
	public static function generate_row_settings() {
		$security = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		if ( '' === $security || ! wp_verify_nonce( $security, 'ur_new_row_added_nonce' ) ) {
			wp_send_json_error( 'Nonce verification failed' );

			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permision Denied' );

			return;
		}
		$form_id = isset( $_POST['form_id'] ) ? intval( $_POST['form_id'] ) : 0;
		$row_id  = isset( $_POST['row_id'] ) ? intval( $_POST['row_id'] ) : 0;

		ob_start();
		echo "<div class='ur-form-row ur-individual-row-settings' data-row-id='" . esc_attr( $row_id ) . "'>";
		do_action( 'user_registration_get_row_settings', $form_id, $row_id );
		echo '</div>';
		$template = ob_get_clean();

		wp_send_json_success( $template );
	}

	/**
	 * AJAX validate selected my account page.
	 */
	public static function my_account_selection_validator() {
		check_ajax_referer( 'user_registration_my_account_selection_validator', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit settings form.', 'user-registration' ) ) );
			wp_die( - 1 );
		}

		// Return if default wp_login is disabled and no redirect url is set.
		if ( isset( $_POST['user_registration_selected_my_account_page'] ) ) {
			if ( is_numeric( $_POST['user_registration_selected_my_account_page'] ) ) {
				$is_page_my_account_page = ur_find_my_account_in_page( sanitize_text_field( wp_unslash( $_POST['user_registration_selected_my_account_page'] ) ) );
				if ( ! $is_page_my_account_page ) {
					wp_send_json_error(
						array(
							'message' => esc_html__(
								'The selected page is not a User Registration & Membership Login or My Account page.',
								'user-registration'
							),
						)
					);
				}
			}
		}

		wp_send_json_success();
	}

	/**
	 * AJAX validate selected lost password page.
	 */
	public static function lost_password_selection_validator() {
		check_ajax_referer( 'user_registration_lost_password_selection_validator', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit settings form.', 'user-registration' ) ) );
			wp_die( - 1 );
		}

		// Return if default wp_login is disabled and no redirect url is set.
		if ( isset( $_POST['user_registration_selected_lost_password_page'] ) ) {
			if ( is_numeric( $_POST['user_registration_selected_lost_password_page'] ) ) {
				$is_page_lost_password_page = ur_find_lost_password_in_page( sanitize_text_field( wp_unslash( $_POST['user_registration_selected_lost_password_page'] ) ) );

				if ( ! $is_page_lost_password_page ) {
					wp_send_json_error(
						array(
							'message' => esc_html__(
								'The selected page does not contain the required lost password shortcode [user_registration_lost_password]',
								'user-registration'
							),
						)
					);
				}
			}
		}

		wp_send_json_success();
	}

	public static function save_payment_settings() {
		check_ajax_referer( 'user_registration_validate_payment_settings_none', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit payment settings.', 'user-registration' ) ) );
			wp_die( - 1 );
		}
		if ( empty( $_POST['section_data'] ) || empty( $_POST['setting_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient Data', 'user-registration' ) ) );
		}

		$setting_id = sanitize_text_field( $_POST['setting_id'] );
		$form_data  = json_decode( wp_unslash( $_POST['section_data'] ), true );

		// Load payment modules to ensure filters are registered
		UR_Admin_Settings::load_payment_modules();

		$validate_before_save = apply_filters( 'urm_validate_' . $setting_id . '_payment_section_before_update', $form_data );

		if ( isset( $validate_before_save['status'] ) && ! $validate_before_save['status'] ) {
			wp_send_json_error(
				array(
					'message' => __( $validate_before_save['message'], 'user-registration' ),
				)
			);
		}
		$is_disabled = isset( $form_data[ 'user_registration_' . $setting_id . '_enabled' ] ) && ! $form_data[ 'user_registration_' . $setting_id . '_enabled' ];
		if ( $is_disabled ) {
			update_option( 'urm_' . $setting_id . '_updated_connection_status', true ); //to check if this setting has been updated at least once
			update_option( 'urm_' . $setting_id . '_connection_status', false );
		} else {
			update_option( 'urm_' . $setting_id . '_connection_status', true );
		}

		if ( 'paypal' === $setting_id ) {
			update_option( 'urm_global_paypal_settings_migrated_', true );
		}

		do_action( 'urm_save_' . $setting_id . '_payment_section', $form_data );
		$message = 'payment-settings' === $setting_id ? 'Settings has been saved successfully' : sprintf( __( 'Payment Setting for %s has been saved successfully.', 'user-registration' ), $setting_id );

		wp_send_json_success(
			array(
				'message'      => $message,
				'is_connected' => ! $is_disabled,
			)
		);
	}

	public static function save_captcha_settings() {
		check_ajax_referer( 'user_registration_validate_captcha_settings_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit captcha settings.', 'user-registration' ) ) );
			wp_die( - 1 );
		}
		if ( empty( $_POST['section_data'] ) || empty( $_POST['setting_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient Data', 'user-registration' ) ) );
		}

		include_once UR_ABSPATH . 'includes/admin/settings/class-ur-settings-page.php';
		include_once UR_ABSPATH . 'includes/admin/settings/class-ur-settings-captcha.php';

		$setting_id = sanitize_text_field( $_POST['setting_id'] );
		$form_data  = json_decode( wp_unslash( $_POST['section_data'] ), true );

		$captcha_settings     = new UR_Settings_Captcha();
		$validate_before_save = $captcha_settings->validate_captcha_settings( $setting_id, $form_data );

		if ( isset( $validate_before_save['status'] ) && ! $validate_before_save['status'] ) {
			wp_send_json_error(
				array(
					'message' => __( $validate_before_save['message'], 'user-registration' ),
				)
			);
		}
		do_action( 'urm_save_captcha_settings', $form_data, $setting_id );
		$message = ( 'captcha-settings' === $setting_id ) ? __( 'Captcha settings saved successfully.', 'user-registration' ) : sprintf( __( 'Captcha Setting for %s has been saved successfully.', 'user-registration' ), $setting_id );

		wp_send_json_success(
			array(
				'message'           => $message,
				'ur_recaptcha_code' => $validate_before_save['ur_recaptcha_code'],
			)
		);
	}

	public static function reset_payment_keys() {

		check_ajax_referer( 'user_registration_validate_payment_settings_none', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to reset captcha keys.', 'user-registration' ) ) );
		}

		$setting_id = isset( $_POST['setting_id'] ) ? sanitize_text_field( wp_unslash( $_POST['setting_id'] ) ) : '';

		if ( empty( $setting_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Setting ID is required.', 'user-registration' ) ) );
		}

		$reset_keys = array();
		switch ( $setting_id ) {
			case 'paypal':
				$reset_keys = array(
					'urm_paypal_connection_status',
					'user_registration_paypal_enabled',
					'user_registration_global_paypal_mode',
					'user_registration_global_paypal_cancel_url',
					'user_registration_global_paypal_return_url',

					'user_registration_global_paypal_client_id',
					'user_registration_global_paypal_client_secret',

					'user_registration_global_paypal_test_email_address',
					'user_registration_global_paypal_test_client_id',
					'user_registration_global_paypal_test_client_secret',

					'user_registration_global_paypal_live_email_address',
					'user_registration_global_paypal_live_client_id',
					'user_registration_global_paypal_live_client_secret',
				);
				break;
			case 'stripe':
				$reset_keys = array(
					'urm_stripe_connection_status',
					'user_registration_stripe_enabled',
					'user_registration_stripe_test_publishable_key',
					'user_registration_stripe_test_secret_key',
					'user_registration_stripe_test_mode',
					'user_registration_stripe_live_publishable_key',
					'user_registration_stripe_live_secret_key',
					'user_registration_stripe_webhook_id_test',
					'user_registration_stripe_webhook_secret_test',
					'user_registration_stripe_webhook_id_live',
					'user_registration_stripe_webhook_secret_live',
				);
				break;
			case 'bank':
				$reset_keys = array(
					'user_registration_bank_enabled',
					'user_registration_global_bank_details',
					'urm_bank_connection_status',
				);
				break;
			case 'mollie':
				$reset_keys = array(
					'urm_mollie_connection_status',
					'user_registration_mollie_enabled',
					'user_registration_mollie_global_test_mode',
					'user_registration_mollie_global_test_publishable_key',
					'user_registration_mollie_global_live_publishable_key',
				);
				break;
			case 'authorize-net':
				$reset_keys = array(
					'urm_authorize-net_connection_status',
					'user_registration_authorize-net_enabled',
					'user_registration_authorize_net_test_mode',
					'user_registration_authorize_net_test_publishable_key',
					'user_registration_authorize_net_test_secret_key',
					'user_registration_authorize_net_live_publishable_key',
					'user_registration_authorize_net_live_secret_key',
				);
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Invalid payment method.', 'user-registration' ) ) );
				return;
		}
		// Reset all keys for the specified payment method.
		foreach ( $reset_keys as $key ) {
			delete_option( $key );
		}
		wp_send_json_success(
			array(
				'message' => sprintf( __( 'Payment method settings for %s have been reset successfully.', 'user-registration' ), $setting_id ),
			)
		);
	}
	/**
	 * Reset captcha keys for a specific captcha type.
	 *
	 * @return void
	 */
	public static function reset_captcha_keys() {
		check_ajax_referer( 'user_registration_validate_captcha_settings_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to reset captcha keys.', 'user-registration' ) ) );
		}

		$setting_id = isset( $_POST['setting_id'] ) ? sanitize_text_field( wp_unslash( $_POST['setting_id'] ) ) : '';

		if ( empty( $setting_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Setting ID is required.', 'user-registration' ) ) );
		}

		// Define option keys to reset for each captcha type
		$reset_keys = array();

		switch ( $setting_id ) {
			case 'v2':
				$reset_keys = array(
					'user_registration_captcha_setting_recaptcha_site_key',
					'user_registration_captcha_setting_recaptcha_site_secret',
					'user_registration_captcha_setting_recaptcha_invisible_site_key',
					'user_registration_captcha_setting_recaptcha_invisible_site_secret',
					'user_registration_captcha_setting_invisible_recaptcha_v2',
					'user_registration_captcha_setting_recaptcha_enable_v2',
				);
				break;

			case 'v3':
				$reset_keys = array(
					'user_registration_captcha_setting_recaptcha_site_key_v3',
					'user_registration_captcha_setting_recaptcha_site_secret_v3',
					'user_registration_captcha_setting_recaptcha_threshold_score_v3',
					'user_registration_captcha_setting_recaptcha_enable_v3',
				);
				break;

			case 'hCaptcha':
				$reset_keys = array(
					'user_registration_captcha_setting_recaptcha_site_key_hcaptcha',
					'user_registration_captcha_setting_recaptcha_site_secret_hcaptcha',
					'user_registration_captcha_setting_recaptcha_enable_hCaptcha',
				);
				break;

			case 'cloudflare':
				$reset_keys = array(
					'user_registration_captcha_setting_recaptcha_site_key_cloudflare',
					'user_registration_captcha_setting_recaptcha_site_secret_cloudflare',
					'user_registration_captcha_setting_recaptcha_cloudflare_theme',
					'user_registration_captcha_setting_recaptcha_enable_cloudflare',
				);
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Invalid captcha type.', 'user-registration' ) ) );
				return;
		}

		// Reset all keys for the specified captcha type
		foreach ( $reset_keys as $key ) {
			delete_option( $key );
		}

		// If all captcha types are disabled, reset the global captcha version option
		$all_captcha_types  = array( 'v2', 'v3', 'hCaptcha', 'cloudflare' );
		$has_active_captcha = false;
		foreach ( $all_captcha_types as $type ) {
			if ( get_option( 'user_registration_captcha_setting_recaptcha_enable_' . $type, false ) ) {
				$has_active_captcha = true;
				break;
			}
		}
		if ( ! $has_active_captcha ) {
			delete_option( 'user_registration_captcha_setting_recaptcha_version' );
		}

		wp_send_json_success(
			array(
				'message' => sprintf( __( 'Captcha keys for %s have been reset successfully.', 'user-registration' ), $setting_id ),
			)
		);
	}

	public static function get_recent_nonce() {
		$form_ids = isset( $_POST['form_ids'] ) ? array_filter( explode( ',', sanitize_text_field( $_POST['form_ids'] ) ) ) : array();
		$for      = isset( $_POST['nonce_for'] ) ? sanitize_text_field( $_POST['nonce_for'] ) : 'registration';

		if ( 'registration' === $for ) {

			if ( empty( $form_ids ) ) {
				wp_send_json_error(
					array(
						__( 'Form ID is missing!', 'user-registration' ),
					)
				);
			}
			foreach ( $form_ids as $form_id ) {
				$form = ur_get_form_fields( $form_id );
				if ( empty( $form ) ) {
					wp_send_json_error(
						array(
							__( 'Form not found!', 'user-registration' ),
						)
					);
				}
			}
		}

		// Strict referer verification
		$referer      = wp_get_referer();
		$allowed_host = parse_url( home_url(), PHP_URL_HOST );
		$referer_host = parse_url( $referer, PHP_URL_HOST );

		if ( ! $referer || $referer_host !== $allowed_host ) {
			wp_send_json_error(
				array(
					__( 'Invalid form submission source.', 'user-registration' ),
				)
			);
		}
		$updated_nonce_array = array();
		switch ( $for ) {
			case 'registration':
				foreach ( $form_ids as $form_id ) {
					$updated_nonce_array[ $form_id ] = wp_create_nonce( 'ur_frontend_form_id-' . $form_id );
				}
				break;
			default:
				$updated_nonce_array = wp_create_nonce( 'ur_login_form_save_nonce' );
				break;
		}

		if ( empty( $updated_nonce_array ) ) {
			wp_send_json_error(
				array(
					__( 'Nonce could not be updated', 'user-registration' ),
				)
			);
		}
		wp_send_json_success( $updated_nonce_array );
	}

	public static function validate_payment_currency() {
		check_ajax_referer( 'user_registration_validate_payment_currency', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit payment settings.', 'user-registration' ) ) );
		}

		if ( empty( $_POST['currency'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient Data', 'user-registration' ) ) );
		}

		$currency = sanitize_text_field( wp_unslash( $_POST['currency'] ) );

		$currency_not_supported_payment_gateways = array();

		// if the currency is not supported by Paypal.
		if ( ! in_array( $currency, paypal_supported_currencies_list() ) ) {
			$currency_not_supported_payment_gateways[] = 'Paypal';
		}

		$currency_not_supported_payment_gateways = apply_filters( 'urm_currency_not_supported_payment_gateways', $currency_not_supported_payment_gateways, $currency );
		if ( ! empty( $currency_not_supported_payment_gateways ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						__( '%1$s is not currently supported by %2$s.', 'user-registration' ),
						$currency,
						implode( ', ', $currency_not_supported_payment_gateways ),
					),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Currency is valid.', 'user-registration' ),
			)
		);
	}

	/**
	 * Handle create/update default form ajax request.
	 * Also adds membership field if missing.
	 *
	 * @since 4.4.1
	 */
	public static function create_default_form() {
		check_ajax_referer( 'wp_rest', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'user-registration' ) ) );
		}

		$enabled_features        = get_option( 'user_registration_enabled_features', array() );
		$is_membership_activated = in_array( 'user-registration-membership', $enabled_features, true );

		$has_membership_plans = false;
		if ( post_type_exists( 'ur_membership' ) ) {
			$has_membership_plans = (bool) get_posts(
				array(
					'post_type'      => 'ur_membership',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
		}

		$is_membership = $is_membership_activated && $has_membership_plans;

		$default_form = get_option( 'user_registration_default_form_page_id', 0 );
		$form_data    = get_post( $default_form );

		if ( $default_form && ! empty( $form_data ) ) {
			if ( $is_membership && false === strpos( $form_data->post_content, '"field_key":"membership"' ) ) {
				return self::add_membership_field_to_form( $default_form, $form_data->post_content );
			}

			wp_send_json_success(
				array(
					'message'  => __( 'Registration form already configured.', 'user-registration' ),
					'form_id'  => $default_form,
					'form_url' => admin_url( 'admin.php?page=add-new-registration&edit-registration=' . $default_form ),
				)
			);
		}

		$membership_field_name = 'membership_field_' . ur_get_random_number();

		$post_content = $is_membership
			? '[[[' .
				'{"field_key":"user_login","general_setting":{"label":"Username","description":"","field_name":"user_login","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":"","username_length":"","username_character":"1"},"icon":"ur-icon ur-icon-user"},' .
				'{"field_key":"user_email","general_setting":{"label":"User Email","description":"","field_name":"user_email","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-email"},' .
				'{"field_key":"user_pass","general_setting":{"label":"User Password","description":"","field_name":"user_pass","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password"},' .
				'{"field_key":"user_confirm_password","general_setting":{"label":"Confirm Password","description":"","field_name":"user_confirm_password","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password-confirm"}' .
				'],[' .
				'{"field_key":"membership","general_setting":{"label":"Membership Field","description":"","field_name":"' . $membership_field_name . '","required":"false","hide_label":"false","membership_listing_option":"all","membership_group":"0"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-membership-field"}' .
				']]]'
			: '[[[' .
				'{"field_key":"user_login","general_setting":{"label":"Username","description":"","field_name":"user_login","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":"","username_length":"","username_character":"1"},"icon":"ur-icon ur-icon-user"},' .
				'{"field_key":"user_email","general_setting":{"label":"User Email","description":"","field_name":"user_email","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-email"},' .
				'{"field_key":"user_pass","general_setting":{"label":"User Password","description":"","field_name":"user_pass","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password"},' .
				'{"field_key":"user_confirm_password","general_setting":{"label":"Confirm Password","description":"","field_name":"user_confirm_password","placeholder":"","required":"1","hide_label":"false"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-password-confirm"}' .
				']]]';

		$default_post_id = wp_insert_post(
			array(
				'post_type'      => 'user_registration',
				'post_title'     => __( 'Registration Form', 'user-registration' ),
				'post_content'   => $post_content,
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		if ( is_wp_error( $default_post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create default form.', 'user-registration' ) ) );
		}

		update_option( 'user_registration_default_form_page_id', $default_post_id );

		if ( $is_membership ) {
			update_option( 'ur_membership_default_membership_field_name', $membership_field_name );
			update_option( 'user_registration_membership_field_added_to_default_form', true );
		}

		wp_send_json_success(
			array(
				'message'              => __( 'Default form created successfully.', 'user-registration' ),
				'form_id'              => $default_post_id,
				'form_url'             => admin_url( 'admin.php?page=add-new-registration&edit-registration=' . $default_post_id ),
				'is_membership'        => $is_membership,
				'has_membership_plans' => $has_membership_plans,
			)
		);
	}

	/**
	 * Helper to add membership field to existing form.
	 */
	private static function add_membership_field_to_form( $form_id, $content ) {
		$membership_field_name = get_option( 'ur_membership_default_membership_field_name', '' );
		if ( empty( $membership_field_name ) ) {
			$membership_field_name = 'membership_field_' . ur_get_random_number();
			update_option( 'ur_membership_default_membership_field_name', $membership_field_name );
		}

		$membership_field_json =
			'{"field_key":"membership","general_setting":{"label":"Membership Field","description":"","field_name":"' .
			$membership_field_name .
			'","required":"false","hide_label":"false","membership_listing_option":"all","membership_group":"0"},"advance_setting":{"custom_class":""},"icon":"ur-icon ur-icon-membership-field"}';

		if ( substr( $content, -3 ) === ']]]' ) {
			$content = substr( $content, 0, -3 ) . '],[' . $membership_field_json . ']]]';
		} else {
			$content .= "\n" . $membership_field_json;
		}

		$updated = wp_update_post(
			array(
				'ID'           => $form_id,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $updated ) || ! $updated ) {
			wp_send_json_error( array( 'message' => __( 'Failed to update the form.', 'user-registration' ) ) );
		}

		update_option( 'user_registration_membership_field_added_to_default_form', true );

		wp_send_json_success(
			array(
				'message'  => __( 'Membership field added successfully.', 'user-registration' ),
				'form_id'  => $form_id,
				'form_url' => admin_url( 'admin.php?page=add-new-registration&edit-registration=' . $form_id ),
			)
		);
	}


	/**
	 * Handle generate required pages ajax request.
	 *
	 * @since 4.4.1
	 */
	public static function generate_required_pages() {
		check_ajax_referer( 'wp_rest', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create pages.', 'user-registration' ) ) );
		}

		$missing_pages = isset( $_POST['missing_pages'] ) ? sanitize_text_field( wp_unslash( $_POST['missing_pages'] ) ) : '';

		if ( empty( $missing_pages ) ) {
			wp_send_json_error( array( 'message' => __( 'No pages specified to create.', 'user-registration' ) ) );
		}

		$result = ur_generate_required_pages( $missing_pages );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'       => __( 'Required pages created successfully.', 'user-registration' ),
				'created_pages' => $result,
			)
		);
	}

	/**
	 * Handle default WordPress login settings.
	 *
	 * @since 4.0
	 */
	public static function handle_default_wordpress_login() {
		check_ajax_referer( 'wp_rest', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to modify login settings.', 'user-registration' ) ) );
		}

		$action = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';

		if ( 'disable' === $action ) {
			// Disable default WordPress login
			update_option( 'user_registration_login_options_prevent_core_login', true );
			wp_send_json_success(
				array(
					'message' => __( 'Default WordPress login has been disabled successfully.', 'user-registration' ),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Invalid action specified.', 'user-registration' ) ) );
		}
	}

	/**
	 * Turn the master "Disable emails" setting back off.
	 */
	public static function enable_emails() {
		check_ajax_referer( 'wp_rest', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to modify email settings.', 'user-registration' ) ) );
		}

		update_option( 'user_registration_email_setting_disable_email', 'no' );

		wp_send_json_success(
			array(
				'message' => __( 'Emails have been enabled successfully.', 'user-registration' ),
			)
		);
	}

	/**
	 * Skip site assistant section.
	 *
	 * @since 4.0
	 */
	public static function skip_site_assistant_section() {
		check_ajax_referer( 'wp_rest', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to skip sections.', 'user-registration' ) ) );
		}

		$section = isset( $_POST['section'] ) ? sanitize_text_field( wp_unslash( $_POST['section'] ) ) : '';

		switch ( $section ) {
			case 'default_wordpress_login':
				// Mark default WordPress login as skipped
				update_option( 'user_registration_default_wordpress_login_skipped', true );
				wp_send_json_success(
					array(
						'message' => __( 'Default WordPress login setting has been skipped.', 'user-registration' ),
					)
				);
				break;

			case 'spam_protection':
				// Mark spam protection as skipped
				update_option( 'user_registration_spam_protection_skipped', true );
				wp_send_json_success(
					array(
						'message' => __( 'Spam protection setting has been skipped.', 'user-registration' ),
					)
				);
				break;

			case 'payment_setup':
				// Mark payment setup as skipped
				update_option( 'user_registration_payment_setup_skipped', true );
				wp_send_json_success(
					array(
						'message' => __( 'Payment setup has been skipped.', 'user-registration' ),
					)
				);
				break;

			case 'membership_field':
				update_option( 'user_registration_membership_field_skipped', true );
				wp_send_json_success(
					array(
						'message' => __( 'Membership field step has been skipped.', 'user-registration' ),
					)
				);
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Invalid section specified.', 'user-registration' ) ) );
				break;
		}
	}

	public static function login_settings_page_validation() {
		check_ajax_referer( 'ur_login_settings_save_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'user-registration' ) ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		if ( ! in_array(
			$type,
			array(
				'user_registration_lost_password_page_id',
				'user_registration_reset_password_page_id',
				'user_registration_login_options_login_redirect_url',
			)
		) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid page type', 'user-registration' ) ) );
		}

		$page_id = isset( $_POST['page_id'] ) ? absint( wp_unslash( $_POST['page_id'] ) ) : 0;

		$default_message = 'Invalid page type';
		switch ( $type ) {
			case 'user_registration_lost_password_page_id':
				if ( empty( $page_id ) ) {
					wp_send_json_error(
						array(
							'message' => esc_html__(
								'Please select a valid lost password page that contains the lost password shortcode [user_registration_lost_password]',
								'user-registration'
							),
						)
					);
				}
				$is_page_lost_password_page = ur_find_lost_password_in_page( $page_id );
				if ( ! $is_page_lost_password_page ) {
					wp_send_json_error(
						array(
							'message' => esc_html__(
								'The selected page does not contain the required lost password shortcode [user_registration_lost_password]',
								'user-registration'
							),
						)
					);
				}
				break;
			case 'user_registration_login_options_login_redirect_url':
				if ( empty( $page_id ) ) {
					wp_send_json_error(
						array(
							'message' => esc_html__(
								'Please select a valid MyAccount or Login page that contains the Login or MyAccount shortcode/block [user_registration_my_account]/[user_registration_login].',
								'user-registration'
							),
						)
					);
				}
				$is_login_page_id = ur_find_my_account_in_page( $page_id );
				if ( ! $is_login_page_id ) {
					wp_send_json_error(
						array(
							'message' => esc_html__(
								'The selected page does not contain the required Login or My Account shortcode/block [user_registration_my_account]/[user_registration_login]',
								'user-registration'
							),
						)
					);
				}
				break;
			default:
				wp_send_json_error( array( 'message' => __( $default_message, 'user-registration' ) ) );
				break;
		}

		wp_send_json_success(
			array(
				'message' => __( 'Page validation successful.', 'user-registration' ),
			)
		);
	}

	public static function activate_dependent_module() {

		if (
			empty( $_POST['security'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'all-forms-ajax-nonce' )
		) {
			wp_send_json_error( 'Nonce verification failed' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';

		if ( empty( $slug ) ) {
			wp_send_json_error( 'Invalid module slug' );
		}

		$enabled_features = get_option( 'user_registration_enabled_features', array() );

		if ( ! in_array( $slug, $enabled_features, true ) ) {
			$enabled_features[] = $slug;
			update_option( 'user_registration_enabled_features', $enabled_features );
		}

		wp_send_json_success();
	}

	/* Update state fields when country is changed.
	 *
	 * @since 6.1.0
	 */
	public static function update_state_field() {
		check_ajax_referer( 'user_registration_update_state_field_nonce', 'security' );

		$country = $_POST['country'];

		$states_json = ur_file_get_contents( '/assets/extensions-json/states.json' );
		$state_list  = json_decode( $states_json, true );

		$states    = isset( $state_list[ $country ] ) ? $state_list[ $country ] : '';
		$option    = '';
		$has_state = false;
		if ( is_array( $states ) ) {
			foreach ( $states as $state_key => $state ) {
				$option .= '<option value="' . $state_key . '">' . esc_html( $state ) . '</option>';
			}
			$has_state = true;
		}

		wp_send_json_success(
			array(
				'state'     => $option,
				'has_state' => $has_state,
			)
		);
	}
}

UR_AJAX::init();
