<?php
/**
 * UserRegistration Admin.
 *
 * @class    UR_Frontend_Form_Handler
 * @version  1.0.0
 * @package  UserRegistration/Frontend
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles frontend user registration forms.
 *
 * @class UR_Frontend_Form_Handler
 * @version 1.0.0
 * @package UserRegistration/Frontend
 */
class UR_Frontend_Form_Handler {

	/**
	 * Form ID.
	 *
	 * @var int
	 */
	public static $form_id = 0;

	/**
	 * Response Data array.
	 *
	 * @var array
	 */
	public static $response_array = array();

	/**
	 * Valid Form data.
	 *
	 * @var array
	 */
	private static $valid_form_data = array();

	/**
	 * Handle frontend form POST data.
	 *
	 * @param array $form_data Submitted form data.
	 * @param int $form_id ID of the form.
	 *
	 * @return void
	 */
	public static function handle_form( $form_data, $form_id ) {

		$logger = ur_get_logger();
		$logger->debug(
			sprintf( '[Form #%d] Function == ***%s()*** - Started execution', $form_id, __FUNCTION__ ),
			array(
				'source'  => 'form-submission',
				'form_id' => $form_id,
			)
		);

		// Reset per-submission static state so a prior request can't leak stale data into this one (e.g. on persistent PHP workers).
		self::$response_array  = array();
		self::$valid_form_data = array();

		self::$form_id      = $form_id;
		$post_content_array = ( $form_id ) ? UR()->form->get_form( $form_id, array( 'content_only' => true, 'publish' => true ) ) : array();

		if ( empty( $post_content_array ) ) {
			$logger->error(
				sprintf( '[Form #%d] Form could not be loaded for submission (missing, unpublished, or trashed).', $form_id ),
				array(
					'source'  => 'form-submission',
					'form_id' => $form_id,
				)
			);

			wp_send_json_error(
				array(
					'message' => __( 'This form is currently unavailable.', 'user-registration' ),
				)
			);
		}

		if ( gettype( $form_data ) != 'array' && gettype( $form_data ) != 'object' ) {
			$form_data = array();
		}

		$logger->info(
			sprintf( '[Form #%d] Getting the form fields...', $form_id ),
			array(
				'source'   => 'form-submission',
				'form_id'  => $form_id,
				'function' => __FUNCTION__,
			)
		);

		$form_field_data = self::get_form_field_data( $post_content_array );

		$logger->notice(
			sprintf( '[Form #%d] Form fields received.', $form_id ),
			array(
				'source'  => 'form-submission',
				'form_id' => $form_id,
			)
		);

		$user_pass = '';

		/**
		 * Filters the valid form data before the frontend handler proceeds.
		 *
		 * Every callback (built-in or third-party) must return $valid_form_data,
		 * possibly augmented, since WordPress feeds each callback's return value
		 * into the next as the reference argument; returning nothing wipes it.
		 *
		 * @param array  $valid_form_data Valid form data, passed and returned by reference.
		 * @param array  $form_field_data Form field data.
		 * @param array  $form_data       Submitted form data.
		 * @param int    $form_id         Form ID.
		 * @param array  $response_array  Response array, passed by reference.
		 * @param string $user_pass       Auto-generated user password, passed by reference.
		 *
		 * @return array
		 */
		apply_filters_ref_array(
			'user_registration_validate_form_data',
			array(
				&self::$valid_form_data,
				$form_field_data,
				$form_data,
				$form_id,
				&self::$response_array,
				&$user_pass,
			)
		);

		$logger->info(
			sprintf( '[Form #%d] Organizing the form data.', $form_id ),
			array(
				'source'  => 'form-submission',
				'form_id' => $form_id,
			)
		);

		self::$valid_form_data = apply_filters( 'user_registration_reorganize_form_data', self::$valid_form_data, $form_field_data, $form_id );

		$logger->info(
			sprintf( '[Form #%d] Form data organized.', $form_id ),
			array(
				'source'  => 'form-submission',
				'form_id' => $form_id,
			)
		);

		// $logger->info( __( 'Getting response', 'user-registration' ), array( 'source' => 'form-submission' ) );
		self::$response_array = apply_filters( 'user_registration_response_array', self::$response_array, $form_data, $form_id );
		// $logger->info( __( 'Response received', 'user-registration' ), array( 'source' => 'form-submission' ) );

		if ( count( self::$response_array ) === 0 ) {
			$configured_user_role     = ur_get_form_setting_by_key( $form_id, 'user_registration_form_setting_default_user_role' );
			$is_known_role            = in_array( $configured_user_role, array_keys( ur_get_default_admin_roles() ) );
			$current_user_capability  = apply_filters( 'ur_registration_user_capability', 'create_users' );
			// A logged-in submitter already passed this same capability check in ur_process_registration(); an anonymous one never has.
			$is_trusted_submitter     = is_user_logged_in() && ( current_user_can( 'manage_options' ) || current_user_can( $current_user_capability ) );
			$user_role                = ( $is_known_role && ( $is_trusted_submitter || ! ur_registration_role_is_privileged( $configured_user_role ) ) ) ? $configured_user_role : 'subscriber';
			$user_role                = apply_filters( 'user_registration_user_role', $user_role, self::$valid_form_data, $form_id );
			$user_registered_date = apply_filters( 'user_registration_user_registered_date', current_time( 'Y-m-d H:i:s' ) );
			$userdata             = array(
				'user_login'      => isset( self::$valid_form_data['user_login'] ) ? self::$valid_form_data['user_login']->value : '',
				'user_pass'       => $user_pass,
				'user_email'      => self::$valid_form_data['user_email']->value,
				'display_name'    => isset( self::$valid_form_data['display_name']->value ) ? self::$valid_form_data['display_name']->value : '',
				'user_url'        => isset( self::$valid_form_data['user_url']->value ) ? self::$valid_form_data['user_url']->value : '',
				// When creating an user, `user_pass` is expected.
				'role'            => $user_role,
				'user_registered' => $user_registered_date,
			);

			$logger->info(
				sprintf( '[Form #%d] Validating form data...', $form_id ),
				array(
					'source'  => 'form-submission',
					'form_id' => $form_id,
				)
			);

			self::$valid_form_data = apply_filters( 'user_registration_before_register_user_filter', self::$valid_form_data, $form_id );

			$logger->info(
				sprintf( '[Form #%d] Form data validation completed.', $form_id ),
				array(
					'source'  => 'form-submission',
					'form_id' => $form_id,
				)
			);

			do_action( 'user_registration_before_register_user_action', self::$valid_form_data, $form_id );

			$logger->debug(
				sprintf( '[Form #%d] Action == ***user_registration_before_register_user_action*** - Triggered.', $form_id ),
				array(
					'source'  => 'form-submission',
					'form_id' => $form_id,
				)
			);

			if ( empty( $userdata['user_login'] ) ) {
				$part_of_email          = explode( '@', $userdata['user_email'] );
				$username               = check_username( $part_of_email[0] );
				$userdata['user_login'] = $username;
			}

			$userdata = apply_filters( 'user_registration_before_insert_user', $userdata, self::$valid_form_data, $form_id );

			// If spam and reject registration return early
			$akismet_result = apply_filters( 'user_registration_get_akismet_validate', $form_id, self::$valid_form_data );
			if ( $akismet_result ) {

				$logger->error(
					sprintf( '[Form #%d] Registration blocked due to potential spam.', $form_id . "\n  " ),
					array(
						'source'  => 'form-submission',
						'form_id' => $form_id,
					)
				);

				wp_send_json_error(
					array(
						'message' => __( 'Registration blocked due to potential spam. Reach out to support for help.', 'user-registration' ),
					)
				);
			}

			$logger->info(
				sprintf( '[Form #%d] Inserting User...', $form_id ),
				array(
					'source'  => 'form-submission',
					'form_id' => $form_id,
				)
			);

			$user_id = wp_insert_user( $userdata ); // Insert user data in users table.

			if ( is_wp_error( $user_id ) ) {
				$err_msg = '';
				foreach ( $user_id->errors as $error ) {
					$err_msg .= '<p>' . $error[0] . '</p>';
				}

				$logger->error(
					sprintf( '[Form #%d] User insertion failed.', $form_id . "\n  " ),
					array(
						'source'  => 'form-submission',
						'form_id' => $form_id,
					)
				);

				wp_send_json_error(
					array(
						'message' => sprintf( __( '%s', 'user-registration' ), $err_msg ),
					)
				);
			}

			$filtered_form_data = apply_filters( 'user_registration_before_user_meta_update', self::$valid_form_data, $user_id, $form_id );

			$logger->info(
				sprintf( '[Form #%d] Inserting the user meta data.', $form_id ),
				array(
					'source'  => 'form-submission',
					'form_id' => $form_id,
				)
			);

			self::ur_update_user_meta( $user_id, $filtered_form_data, $form_id ); // Insert user data in usermeta table.

			$logger->notice(
				sprintf( '[Form #%d] User meta data inserted successfully.', $form_id ),
				array(
					'source'  => 'form-submission',
					'form_id' => $form_id,
				)
			);

			if ( $user_id > 0 ) {
				$logger->notice(
					sprintf( '[Form #%d] User created successfully.', $form_id ),
					array(
						'source'  => 'form-submission',
						'form_id' => $form_id,
					)
				);

				$user = get_userdata( $user_id );

				if ( $user ) {
					$user_data = array(
						'ID'              => $user->ID,
						'user_login'      => $user->user_login,
						'user_email'      => $user->user_email,
						'display_name'    => $user->display_name,
						'user_nicename'   => $user->user_nicename,
						'user_registered' => $user->user_registered,
						'roles'           => $user->roles,
					);

					$logger->debug(
						sprintf( '[Form #%d] Created user data:', $form_id ) . "\n" . wp_json_encode( $user_data, JSON_PRETTY_PRINT ),
						array(
							'source'  => 'form-submission',
							'form_id' => $form_id,
							'user_id' => $user_id,
						)
					);
				}

				do_action( 'user_registration_after_user_meta_update', self::$valid_form_data, $form_id, $user_id );

				$logger->debug(
					sprintf( '[Form #%d] Action hook == ***user_registration_after_user_meta_update*** - Triggered.', $form_id ),
					array(
						'source'  => 'form-submission',
						'form_id' => $form_id,
					)
				);

				$login_option = ur_get_user_login_option( $user_id );

				$logger->info(
					sprintf( '[Form #%d] User Login Option: %s', $form_id, $login_option ),
					array(
						'source'  => 'form-submission',
						'form_id' => $form_id,
					)
				);

				$success_params = array(
					'username' => isset( $userdata['user_login'] ) ? $userdata['user_login'] : '',
				);

				if ( isset( $_POST['ur_stripe_payment_method'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification
					if ( 'auto_login' === $login_option ) {
						$success_params['auto_login'] = true;
					}
				} elseif ( ur_string_to_bool( ur_get_single_post_meta( $form_id, 'user_registration_enable_paypal_standard', false ) ) ) {
					if ( 'auto_login' === $login_option ) {
						$success_params['auto_login'] = false;
					}
				} elseif ( isset( $_POST['is_membership_active'] ) ) {
					if ( 'auto_login' === $login_option ) {
						$success_params['auto_login']      = true;
						$success_params['membership_type'] = $_POST['membership_type'];
					}
				} elseif ( 'auto_login' === $login_option ) {
					delete_transient( 'urm_pending_login_' . $user_id );
					wp_clear_auth_cookie();
					$remember = apply_filters( 'user_registration_autologin_remember_user', false );
					wp_set_auth_cookie( $user_id, $remember );
					$success_params['auto_login'] = true;
				}
				$success_params['success_message_positon'] = ur_get_single_post_meta( $form_id, 'user_registration_form_setting_success_message_position', '1' );
				$success_params['form_login_option']       = ! ur_string_to_bool( get_option( 'user_registration_enable_email_confirmation', true ) ) && 'email_confirmation' === $login_option ? 'email_confirmation' : $login_option;

				$redirect_timeout                   = (int) ur_get_single_post_meta( $form_id, 'user_registration_form_setting_redirect_after', '2' ) * 1000;
				$redirect_after_registration        = ur_get_single_post_meta( $form_id, 'user_registration_form_setting_redirect_after_registration', ur_get_default_redirect_after_registration( $form_id ) );
				$success_params['redirect_timeout'] = 'no-redirection' !== $redirect_after_registration ? apply_filters( 'user_registration_hold_success_message_before_redirect', $redirect_timeout ) : 0;

				$redirect_url = ur_get_form_redirect_url( $form_id );

				if ( ! empty( $redirect_url ) ) {
					$success_params['redirect_url'] = $redirect_url;
				}
				$success_params = apply_filters( 'user_registration_success_params', $success_params, self::$valid_form_data, $form_id, $user_id );
				// $logger->info( __( 'Processing form data', 'user-registration' ), array( 'source' => 'form-submission' ) );
				foreach ( self::$valid_form_data as $field_key => $field_value ) {
					if ( isset( $field_value->extra_params ) && isset( $field_value->extra_params['field_key'] ) ) {
						if ( 'file' === $field_value->extra_params['field_key'] ) {
							$file_data   = explode( ',', get_user_meta( $user_id, 'user_registration_' . $field_value->field_name, true ) );
							$upload_data = array();

							foreach ( $file_data as $key => $file_value ) {
								$file = isset( $file_value ) ? wp_get_attachment_url( $file_value ) : '';
								array_push( $upload_data, $file );
							}

							$field_value->value                  = $upload_data;
							self::$valid_form_data[ $field_key ] = $field_value;
						}

						// Process for file upload.
						if ( 'profile_picture' === $field_value->extra_params['field_key'] ) {
							$profile_file_data                   = get_user_meta( $user_id, 'user_registration_' . $field_value->field_name, true );
							$profile_file                        = wp_get_attachment_url( $profile_file_data );
							$field_value->value                  = $profile_file;
							self::$valid_form_data[ $field_key ] = $field_value;
						}
					}
				}
				if ( ! isset( $success_params['payment_process'] ) || $success_params['payment_process'] == false ) {

					do_action( 'user_registration_after_register_user_action', self::$valid_form_data, $form_id, $user_id );

					$logger->debug(
						sprintf( '[Form #%d] Action hook == ***user_registration_after_register_user_action*** - Triggered.', $form_id ),
						array(
							'source'  => 'form-submission',
							'form_id' => $form_id,
						)
					);
				}

				$success_params = apply_filters( 'user_registration_success_params_before_send_json', $success_params, self::$valid_form_data, $form_id, $user_id );

				$logger->debug(
					sprintf( '[Form #%d] Success params:', $form_id ) . "\n" . wp_json_encode( $success_params, JSON_PRETTY_PRINT ),
					array(
						'source'  => 'form-submission',
						'form_id' => $form_id,
						'user_id' => $user_id,
					)
				);

				$logger->success(
					sprintf( '[Form #%d] =============== ***User registration process completed*** ===============', $form_id ) . "\n   ",
					array(
						'source'  => 'form-submission',
						'form_id' => $form_id,
					)
				);

				if ( empty( $_POST['ur_fallback_submit'] ) ) {
					wp_send_json_success( $success_params );
				} else {
					apply_filters( 'user_registration_post_success_message', __( 'User successfully registered.', 'user-registration' ) );
				}
			}

			$logger->error(
				sprintf( '[Form #%d] Something wen wrong!. Getting the invalid user ID %s. Please try again. ', $form_id, $user_id ) . "\n",
				array(
					'source'  => 'form-submission',
					'form_id' => $form_id,
				)
			);

			if ( empty( $_POST['ur_fallback_submit'] ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Something went wrong! please try again', 'user-registration' ),
					)
				);
			}
		} else {
			apply_filters( 'user_registration_post_registration_errors', self::$response_array );

			if ( empty( $_POST['ur_fallback_submit'] ) ) {
				wp_send_json_error(
					array(
						'message' => array_unique( self::$response_array, SORT_REGULAR ),
					)
				);
			} else {
				// Store errors in transient for traditional form submission display
				if ( ! empty( self::$response_array ) ) {
					$form_errors_key = 'ur_form_errors_' . $form_id . '_' . wp_create_nonce( 'ur_form_errors' );
					set_transient( $form_errors_key, self::$response_array, 60 );
				}
			}
		}// End if().
	}

	/**
	 * Get form field data by post_content array passed.
	 *
	 * @param array $post_content_array Post Content Array.
	 *
	 * @return array
	 */
	public static function get_form_field_data( $post_content_array ) {
		$form_field_data_array = array();
		foreach ( $post_content_array as $row_index => $row ) {
			foreach ( $row as $grid_index => $grid ) {
				foreach ( $grid as $field_index => $field ) {
					$field_name = isset( $field->advance_setting->field_name ) ? $field->advance_setting->field_name : ( isset( $field->general_setting->field_name ) ? $field->general_setting->field_name : '' );
					if ( 'confirm_user_pass' != $field_name ) {
						array_push( $form_field_data_array, $field );
					}
				}
			}
		}
		return ( $form_field_data_array );
	}

	/**
	 * Update form data to usermeta table.
	 *
	 * @param int $user_id User ID.
	 * @param array $valid_form_data All valid form data.
	 * @param int $form_id Form ID.
	 *
	 * @return void
	 */
	public static function ur_update_user_meta( $user_id, $valid_form_data, $form_id ) {

		foreach ( $valid_form_data as $data ) {
			$field_key = isset( $data->extra_params['field_key'] ) ? $data->extra_params['field_key'] : '';

			if ( ! in_array( trim( $data->field_name ), ur_get_user_table_fields() ) && $field_key !== 'file' ) {
				$field_name            = $data->field_name;
				$fields_without_prefix = ur_get_fields_without_prefix();

				if ( ! in_array( $field_key, $fields_without_prefix ) ) {
					$field_name = 'user_registration_' . $field_name;
				}

				if ( isset( $data->extra_params['field_key'] ) && ( 'checkbox' === $data->extra_params['field_key'] || 'learndash_course' === $data->extra_params['field_key'] ) ) {
					$data->value = isset( $data->value ) && ! is_array( $data->value ) ? json_decode( $data->value ) : $data->value;
				} elseif ( isset( $data->extra_params['field_key'] ) && ( 'wysiwyg' === $data->extra_params['field_key'] ) ) {
					$data->value = sanitize_text_field( htmlentities( $data->value ) );
				}
				update_user_meta( $user_id, $field_name, $data->value );
			}
			if ( 'user_url' === $data->field_name ) {
				$data->value = sanitize_text_field( $data->value );
				update_user_meta( $user_id, $data->field_name, $data->value );
			}
		}
		update_user_meta( $user_id, 'ur_form_id', $form_id );

		/**
		 * Saving the user ip in user meta.
		 *
		 * @since  3.1.0
		 */
		$user_ip = ur_get_ip_address();
		update_user_meta( $user_id, 'ur_user_ip', $user_ip );

		$login_option = ur_get_user_login_option( $user_id );
		update_user_meta( $user_id, 'ur_login_option', $login_option );

		// Server-side payment-gate flag so login fails closed even if the client omits membership signals. UR-4811.
		if ( 'payment' === $login_option && function_exists( 'ur_check_module_activation' ) && ur_check_module_activation( 'membership' ) ) {
			$hidden_fields = isset( $_POST['urcl_hide_fields'] ) ? (array) json_decode( wp_unslash( $_POST['urcl_hide_fields'] ), true ) : array();
			foreach ( $valid_form_data as $field ) {
				if ( isset( $field->extra_params['field_key'] ) && 'membership' === $field->extra_params['field_key'] ) {
					if ( ! in_array( $field->field_name, $hidden_fields, true ) ) {
						update_user_meta( $user_id, 'ur_requires_payment', 'yes' );
					}
					break;
				}
			}
		}

		$current_language = ur_get_current_language();
		$current_language = isset( $_POST['registration_language'] ) ? ur_clean( $_POST['registration_language'] ) : $current_language; //phpcs:ignore.
		update_user_meta( $user_id, 'ur_registered_language', $current_language );
		$login_option = ur_get_user_login_option( $user_id );

		if ( ! empty( $_POST['membership_type'] ) ) {
			$hash = hash_hmac(
				'sha256',
				(string) $user_id,
				wp_salt( 'auth' )
			);

			set_transient( 'urm_pending_login_' . $user_id, $hash, 2 * HOUR_IN_SECONDS );
			// Bind the pending nopriv payment/auto-login flow to this browser session.
			setcookie(
				'urm_pending_login_' . $user_id,
				$hash,
				time() + 2 * HOUR_IN_SECONDS,
				defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				is_ssl(),
				true
			);
		}
	}
}

// Add filter to populate user_registration_post_registration_errors with actual errors
add_filter(
	'user_registration_post_registration_errors',
	function ( $errors ) {
		// Check if we're in a traditional form submission context
		if ( ! empty( $_POST['ur_fallback_submit'] ) ) {
			// Get the current form ID
			$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
			if ( $form_id > 0 ) {
				// Check for stored errors in transient
				$form_errors_key = 'ur_form_errors_' . $form_id . '_' . wp_create_nonce( 'ur_form_errors' );
				$stored_errors   = get_transient( $form_errors_key );
				if ( $stored_errors ) {
					$errors = $stored_errors;
					// Delete the transient after retrieving
					delete_transient( $form_errors_key );
				}
			}
		}
		return $errors;
	}
);

// Add filter to provide success message
add_filter(
	'user_registration_post_success_message',
	function ( $success_message ) {
		// Check if we're in a traditional form submission context
		if ( ! empty( $_POST['ur_fallback_submit'] ) ) {
			$success_message = __( 'User successfully registered.', 'user-registration' );
		}
		return $success_message;
	}
);

return new UR_Frontend_Form_Handler();
