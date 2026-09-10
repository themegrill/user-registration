<?php
/**
 * User Registration Form Validation.
 *
 * @class    UR_Form_Validation
 * @version  1.0.0
 * @package  UserRegistration/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UR_Form_Validation Class
 *
 * This class handles validations for all frontend submitted forms.
 */
class UR_Form_Validation extends UR_Validation {

	/**
	 * Response array variable.
	 * It stores validation errors which is sent to UR_Frontend_Form_Handler::$response_array.
	 *
	 * @var array
	 */
	private $response_array = array();

	/**
	 * Valid form data variable.
	 * It stores valid form data that is sent to UR_Frontend_Form_Handler::$valid_form_data.
	 *
	 * @var array
	 */
	public $valid_form_data = array();

	/**
	 * Form Id.
	 *
	 * @var integer
	 */
	private $form_id = 0;

	/**
	 * Field Validations map.
	 *
	 * @var [array]
	 */
	private $field_validations;


	/**
	 * Class Constructor.
	 */
	public function __construct() {
		add_action( 'user_registration_validate_form_data', array( $this, 'validate_form' ), 10, 6 );
		add_action( 'user_registration_validate_profile_update', array( $this, 'validate_update_profile' ), 10, 4 );
		add_filter( 'user_registration_reorganize_form_data', array( $this, 'reorganize_form_data' ), 10, 3 );
	}

	/**
	 * Reorganize form data according to the actual form structure instead of the submitted data structure
	 *
	 * @param $valid_form_data
	 * @param $form_field_data
	 * @param $form_id
	 *
	 * @return array
	 */
	public function reorganize_form_data( $valid_form_data, $form_field_data, $form_id ) {
		if ( ! is_array( $valid_form_data ) ) {
			$valid_form_data = array();
		}

		if ( empty( $form_field_data ) ) {
			return $valid_form_data;
		}
		$new_form_data = array();

		$form_row_data = get_post_meta( $form_id, 'user_registration_form_row_data', true );

		$row_datas = ! empty( $form_row_data ) ? json_decode( $form_row_data ) : array();

		$repeater_fields     = array();
		$repeater_field_data = array();
		foreach ( $row_datas as $individual_row_data ) {

			if ( isset( $individual_row_data->repeater_id ) && isset( $individual_row_data->field_name ) ) {
				array_push( $repeater_fields, $individual_row_data->fields );

				$repeater_field_data[ $individual_row_data->field_name ] = $valid_form_data[ $individual_row_data->field_name ] ?? array();
			}
		}

		foreach ( $form_field_data as $key => $data ) {
			if ( empty( $data->general_setting->field_name ) ) {
				continue;
			}
			$field_name = $data->general_setting->field_name;
			if ( in_array( $field_name, $repeater_fields ) ) {
				continue;
			} elseif ( array_key_exists( $field_name, $valid_form_data ) ) {
				$new_form_data[ $field_name ] = $valid_form_data[ $field_name ];
			}
		}

		$new_form_data = array_merge( $new_form_data, $repeater_field_data );
		return $new_form_data;
	}

	/**
	 * Validates the user submitted registration form field values.
	 *
	 * @param [arary]  $valid_form_data UR_Frontend_Form_Handler::$valid_form_data reference.
	 * @param [arary]  $form_field_data Form Field Data.
	 * @param [arary]  $form_data Form Data.
	 * @param [int]    $form_id Form Id.
	 * @param [array]  $response_array UR_Frontend_Form_Handler::$response_array reference.
	 * @param [string] $user_pass User Password reference.
	 * @return array Valid form data, returned so a later callback on the same filter doesn't wipe it.
	 */
	public function validate_form( &$valid_form_data, $form_field_data, $form_data, $form_id, &$response_array, &$user_pass ) {
		$this->valid_form_data = $valid_form_data;
		$this->response_array  = $response_array;
		$this->form_id         = $form_id;

		$this->match_email( $form_field_data, $form_data );

		// Triger validation method for user fields. Useful for custom fields validation.
		$this->add_hook( $form_field_data, $form_data );

		$enable_auto_password_generation = ur_string_to_bool( ur_get_single_post_meta( $form_id, 'user_registration_pro_auto_password_activate' ) );

		if ( $enable_auto_password_generation ) {
			/**
			 * Action auto generate password.
			 *
			 * @param array $form_id The form id.
			 */
			do_action( 'user_registration_auto_generate_password', $form_id );
			/**
			 * Filter auto generated password.
			 * Default value is 'user_pass'.
			 */
			$user_pass = apply_filters( 'user_registration_auto_generated_password', 'user_pass' );
			$this->validate_form_data( $form_id, $form_field_data, $form_data );
		} else {
			$this->match_password( $form_field_data, $form_data );
			$this->validate_form_data( $form_id, $form_field_data, $form_data );
			$this->validate_password_data( $form_field_data, $form_data );
			$user_pass = isset( $this->valid_form_data['user_pass']->value ) ? $this->valid_form_data['user_pass']->value : '';
		}
		if ( ! is_null( $this->response_array ) ) {
			// Modify UR_Frontend_Form_Handler::$response_array variable.
			$response_array = array_merge( $response_array, $this->response_array );
		}

		// Modify UR_Frontend_Form_Handler::$valid_form_data variable.
		$valid_form_data = $this->valid_form_data;

		return $valid_form_data;
	}


	/**
	 * Validation from each field's class validation() method.
	 * Sanitization from get_sanitize_value().
	 *
	 * @param int   $form_id Form ID.
	 * @param  array $form_field_data Form Field Data.
	 * @param  array $form_data  Form data to validate.
	 */
	public function validate_form_data( $form_id, $form_field_data = array(), $form_data = array() ) {
		// Submitted field data is client-supplied JSON, so an entry can arrive without field_name (e.g. a display-only field).
		$request_form_keys = wp_list_pluck( array_filter( $form_data, function ( $field ) {
			return isset( $field->field_name );
		} ), 'field_name' );

		/**
		 * Filter the form field data.
		 *
		 * @param array $form_field_data The form data.
		 * @param int $form_id The form ID.
		 */
		$form_field_data = apply_filters( 'user_registration_add_form_field_data', $form_field_data, $form_id );

		$required_fields     = wp_list_pluck( wp_list_pluck( $form_field_data, 'general_setting' ), 'field_name' );
		$duplicate_field_key = array_diff_key( $request_form_keys, array_unique( $request_form_keys ) );
		if ( count( $duplicate_field_key ) > 0 ) {
			array_push( $this->response_array, __( 'Duplicate field key in form, please contact site administrator.', 'user-registration' ) );
		}

		$contains_search = count( array_intersect( ur_get_required_fields(), $request_form_keys ) ) == count( ur_get_required_fields() );

		if ( false === $contains_search ) {
			array_push( $this->response_array, __( 'Required form field not found.', 'user-registration' ) );
		}

		foreach ( $form_data as $data ) {

			if ( isset( $data->field_type ) && 'repeater' === $data->field_type ) {
				/**
				 * Action validate honeypot container.
				 *
				 * @param array $data The data.
				 * @param string $filter_hook The dynamic Filter hook.
				 * @param int $form_id The form ID.
				 * @param array $form_data The form data.
				 */
				list( $this->response_array, $this->valid_form_data ) = apply_filters(
					'user_registration_validate_repeater_fields',
					array(
						$this->response_array,
						$this->valid_form_data,
					),
					$data,
					$form_id,
					$form_data,
					$form_field_data
				);
				$required_fields                                      = apply_filters( 'user_registration_missing_repeater_field_keys', $required_fields, $form_id );

			} else {

				list( $response_array, $valid_form_data ) = user_registration_validate_form_field_data( $data, $form_data, $form_id, $this->response_array, $form_field_data, array() );
				$this->response_array                     = $response_array;
				$this->valid_form_data                    = array_merge( $this->valid_form_data, $valid_form_data );
			}
		}

		// Check if a required field is missing.
		$missing_item = array_diff( $required_fields, $request_form_keys );

		if ( count( $missing_item ) > 0 ) {

			foreach ( $missing_item as $key => $value ) {

				$ignorable_field = array( 'user_pass', 'user_confirm_password', 'user_confirm_email', 'stripe_gateway', 'authorizenet_gateway' );

				// Ignoring confirm password and confirm email field, since they are handled separately.
				if ( ! in_array( $value, $ignorable_field, true ) ) {
					$this->ur_missing_field_validation( $form_field_data, $key, $value );
				}
			}
		}
	}

	/**
	 * Triger validation method for user fields
	 * Useful for custom fields validation
	 *
	 * @param array $form_field_data Form Field Data.
	 * @param array $form_data Form Data.
	 */
	public function add_hook( $form_field_data = array(), $form_data = array() ) {
		$form_key_list = wp_list_pluck( wp_list_pluck( $form_field_data, 'general_setting' ), 'field_name' );
		foreach ( $form_data as $data ) {
			if ( isset( $data->field_type ) && 'repeater' === $data->field_type ) {
				if ( isset( $data->value ) ) {
					$data_arr = (array) $data->value;
					foreach ( $data_arr as $row_id => $value ) {
						foreach ( $value as $field_data ) {
							$field_name = isset( $field_data->field_name ) ? trim( str_replace( 'user_registration_', '', $field_data->field_name ) ) : '';
							$this->run_field_validations_on_registration( $form_field_data, $field_name, $form_key_list );
						}
					}
				}
			} elseif ( isset( $data->field_name ) ) {
				$this->run_field_validations_on_registration( $form_field_data, $data->field_name, $form_key_list );
			}
		}
	}


	/**
	 * Sanitize form data
	 *
	 * @param  obj $form_data Form data.
	 * @return object
	 */
	public static function get_sanitize_value( &$form_data ) {

		$field_key = isset( $form_data->extra_params['field_key'] ) ? $form_data->extra_params['field_key'] : '';
		$fields    = ur_get_registered_form_fields();

		if ( in_array( $field_key, $fields ) ) {

			switch ( $field_key ) {
				case 'user_email':
				case 'email':
					$form_data->value = sanitize_email( $form_data->value );
					break;
				case 'user_login':
					$form_data->value = sanitize_user( $form_data->value );
					break;
				case 'user_url':
					$form_data->value = esc_url_raw( $form_data->value );
					break;
				case 'textarea':
				case 'description':
					$form_data->value = sanitize_textarea_field( $form_data->value );
					break;
				case 'number':
					$form_data->value = floatval( $form_data->value );
					break;
				case 'nickname':
				case 'first_name':
				case 'last_name':
				case 'display_name':
				case 'text':
				case 'radio':
				case 'privacy_policy':
				case 'mailchimp':
				case 'mailerlite':
				case 'select':
				case 'country':
				case 'date':
					$form_data->value = sanitize_text_field( isset( $form_data->value ) ? $form_data->value : '' );
					break;
				case 'file':
					$form_data->value = isset( $form_data->value ) ? $form_data->value : '';
					break;
				case 'checkbox':
					$form_data->value = isset( $form_data->value ) ? wp_kses_post( $form_data->value ) : '';
			}
		}
		/**
		 * Filter the sanitize field.
		 *
		 * @param array $form_data The form data.
		 * @param string $field_key The form key.
		 */
		return apply_filters( 'user_registration_sanitize_field', $form_data, $field_key );
	}


	/**
	 * Match email and confirm email field.
	 *
	 * @param  array $form_field_data Form Field Data.
	 * @param  obj   $form_data Form data submitted.
	 * @return obj $form_data
	 */
	private function match_email( $form_field_data, &$form_data ) {

		$confirm_email_value = '';
		$has_confirm_email   = false;
		$email               = '';

		// Submitted field data is client-supplied JSON, so an entry can arrive without field_name (e.g. a display-only field).
		$form_data_field = wp_list_pluck( array_filter( $form_data, function ( $field ) {
			return isset( $field->field_name );
		} ), 'field_name' );
		$form_key_list   = wp_list_pluck( wp_list_pluck( $form_field_data, 'general_setting' ), 'field_name' );

		// Check if a required field is missing.
		$missing_item = array_diff( $form_key_list, $form_data_field );

		// Check if the missing field is required confirm email field.
		if ( in_array( 'user_confirm_email', $missing_item ) ) {
			$has_confirm_email = true;
		}

		foreach ( $form_data as $index => $single_data ) {

			if ( ! isset( $single_data->field_name ) ) {
				continue;
			}

			if ( 'user_confirm_email' == $single_data->field_name ) {
				$confirm_email_value = $single_data->value;
				$has_confirm_email   = true;
				unset( $form_data[ $index ] );
			}
			if ( 'user_email' == $single_data->field_name ) {
				$email = $single_data->value;
			}
		}

		if ( $has_confirm_email ) {
			if ( empty( $confirm_email_value ) ) {
				array_push( $this->response_array, __( '<strong>Confirm Email</strong> is required.', 'user-registration' ) );
			} elseif ( strcasecmp( $confirm_email_value, $email ) != 0 ) {
				array_push( $this->response_array, get_option( 'user_registration_form_submission_error_message_confirm_email', __( 'Email and confirm email not matched', 'user-registration' ) ) );
			}
		}
		return $form_data;
	}


	/**
	 * Match password and confirm password field
	 *
	 * @param  array $form_field_data Form Field Data.
	 * @param  obj   $form_data Form data submitted.
	 * @return obj $form_data
	 */
	public function match_password( $form_field_data, &$form_data ) {
		$confirm_password     = '';
		$has_confirm_password = false;
		$password             = '';

		// Submitted field data is client-supplied JSON, so an entry can arrive without field_name (e.g. a display-only field).
		$form_data_field = wp_list_pluck( array_filter( $form_data, function ( $field ) {
			return isset( $field->field_name );
		} ), 'field_name' );
		$form_key_list   = wp_list_pluck( wp_list_pluck( $form_field_data, 'general_setting' ), 'field_name' );

		// Check if a required field is missing.
		$missing_item = array_diff( $form_key_list, $form_data_field );

		// Check if the missing field is required confirm password field.
		if ( in_array( 'user_confirm_password', $missing_item ) ) {
				$has_confirm_password = true;
		}

		foreach ( $form_data as $index => $single_data ) {
			if ( ! isset( $single_data->field_name ) ) {
				continue;
			}

			if ( 'user_confirm_password' == $single_data->field_name ) {
				$confirm_password     = $single_data->value;
				$has_confirm_password = true;
				unset( $form_data[ $index ] );
			}
			if ( 'user_pass' == $single_data->field_name ) {
				$password = $single_data->value;
			}
		}

		if ( $has_confirm_password ) {
			if ( empty( $confirm_password ) ) {
				array_push( $this->response_array, __( '<strong>Confirm Password</strong> is required.', 'user-registration' ) );
			} elseif ( strcmp( $confirm_password, $password ) != 0 ) {
				array_push( $this->response_array, get_option( 'user_registration_form_submission_error_message_confirm_password', __( 'Password and confirm password not matched', 'user-registration' ) ) );
			}
		}
		return $form_data;
	}


	/**
	 * Validate password to check if match username or email address.
	 *
	 * @param  array $form_field_data Form field data.
	 * @param  array $form_data  Form data to validate.
	 */
	private function validate_password_data( $form_field_data = array(), $form_data = array() ) {
		$email_value    = '';
		$username_value = '';
		$password_value = '';

		// Find email, username and password value.
		foreach ( $form_data as $data ) {
			if ( isset( $data->extra_params ) && 'object' === gettype( $data->extra_params ) ) {
				$data->extra_params = (array) $data->extra_params;
			}

			if ( isset( $data->extra_params['field_key'] ) ) {
				if ( 'user_email' === $data->extra_params['field_key'] ) {
					$email_value = strtolower( $data->value );
				}
				if ( 'user_login' === $data->extra_params['field_key'] ) {
					$username_value = strtolower( $data->value );
				}
				if ( 'user_pass' === $data->extra_params['field_key'] ) {
					$password_value = strtolower( $data->value );
				}
			}
		}

		if ( $password_value === $email_value || $password_value === $username_value ) {
			array_push( $this->response_array, __( 'Password should not match with Username or Email address.', 'user-registration' ) );
		}
	}


	/**
	 * Validate missing required fields.
	 *
	 * @param  array  $form_field_data Form Field Data.
	 * @param int    $key index of missing field in Form Field Data.
	 * @param string $value field name of missing field.
	 * @return obj $form_data
	 */
	private function ur_missing_field_validation( $form_field_data, $key, $value ) {

		if ( isset( $form_field_data[ $key ]->general_setting->field_name ) && $value == $form_field_data[ $key ]->general_setting->field_name ) {

			if ( isset( $form_field_data[ $key ]->general_setting->required ) && ur_string_to_bool( $form_field_data[ $key ]->general_setting->required ) ) {

				// Check for the field visibility settings.
				if ( isset( $form_field_data[ $key ]->advance_setting->field_visibility ) && 'edit_form' === $form_field_data[ $key ]->advance_setting->field_visibility ) {
					return;
				} else {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing
					$urcl_hide_fields = isset( $_POST['urcl_hide_fields'] ) ? (array) json_decode( stripslashes( $_POST['urcl_hide_fields'] ), true ) : array();
					if ( ! in_array( $form_field_data[ $key ]->general_setting->field_name, $urcl_hide_fields, true ) ) {
						$field_label = $form_field_data[ $key ]->general_setting->label;
						/* translators: %s - Field Label */
						$response = sprintf( __( '<strong>%s</strong> is a required field.', 'user-registration' ), $field_label );
						array_push( $this->response_array, $response );
					}
				}
			}
		}
	}

	/**
	 * Set default validations for fields.
	 */
	public function set_fields_validations() {
		$validations = array(
			'user_email'     => array( 'is_email' ),
			'email'          => array( 'is_email' ),
			'user_url'       => array( 'is_url' ),
			'privacy_policy' => array( 'is_boolean' ),
			'number'         => array( 'is_numeric' ),
		);
		/**
		 * Filter the field validations.
		 *
		 * @param array $validations The validation list.
		 */
		$this->field_validations = apply_filters( 'user_registration_field_validations', $validations );
	}


	/**
	 * Returns validation set for a field.
	 *
	 * @param string $field_key Field Key.
	 * @return array
	 */
	public function get_field_validations( $field_key = '' ) {

		if ( is_null( $this->field_validations ) ) {
			$this->set_fields_validations();
		}

		$validations = $this->field_validations;

		return isset( $validations[ $field_key ] ) ? $validations[ $field_key ] : array();
	}


	/**
	 * Add a validation error to UR_Frontend_Form_Handler response array.
	 *
	 * @param [WP_Error] $error Error object.
	 * @param string     $label Field label.
	 * @return void
	 */
	public function add_error( $error, $label = '', $response_array = array() ) {
		if ( ! empty( $error ) && is_wp_error( $error ) ) {
			$error_code = $error->get_error_code();
			$message    = $this->get_error_message( $error_code, $label );

			array_push( $response_array, $message );
		}
		return $response_array;
	}


	/**
	 * Returns a complete error message for the error code.
	 *
	 * @param string $error_code UR_Validation error code.
	 * @param string $field_label Field label.
	 * @return string Error Message.
	 */
	public function get_error_message( $error_code = '', $field_label = '' ) {
		$errors = array(
			'invalid_email'     => ur_string_translation( 0, 'user_registration_invalid_email_error_message', 'Please enter a valid email for %s.' ),
			'invalid_url'       => ur_string_translation( 0, 'user_registration_invalid_url_error_message', 'Please enter a valid url for %s.' ),
			'invalid_date'      => ur_string_translation( 0, 'user_registration_invalid_date_error_message', 'Please enter a valid date for %s.' ),
			'empty_field'       => ur_string_translation( 0, 'user_registration_empty_field_error_message', '%s is a required field.' ),
			'non_boolean_value' => ur_string_translation( 0, 'user_registration_non_boolean_value_error_message', 'Please enter a valid value for %s.' ),
			'non_numeric_data'  => ur_string_translation( 0, 'user_registration_non_numeric_data_error_message', 'Please enter a numeric value for %s.' ),
		);

		$error_code = str_replace( 'user_registration_validation_', '', $error_code );

		if ( in_array( $error_code, array_keys( $errors ), true ) ) {
			return sprintf(
				__( $errors[ $error_code ], 'user-registration' ), // phpcs:ignore
				"<strong>$field_label</strong>"
			);
		} else {
			return sprintf(
				'The value you entered for <strong>%s</strong> is invalid.',
				$field_label
			);
		}
	}


	/**
	 * Checks if a field is required using the value set in form field settings
	 * and conditional logic.
	 *
	 * @param [object] $field Field object.
	 * @param array    $form_data Form Data.
	 * @return boolean
	 */
	public function is_field_required( $field, $form_data = array() ) {

		$is_required = false;

		if ( ! empty( $field ) ) {
			$required         = isset( $field->general_setting->required ) ? $field->general_setting->required : false;
			$urcl_hide_fields = isset( $_POST['urcl_hide_fields'] ) ? (array) json_decode( stripslashes( $_POST['urcl_hide_fields'] ), true ) : array(); //phpcs:ignore;
			$field_name       = isset( $field->general_setting->field_name ) ? $field->general_setting->field_name : '';

			if ( ! in_array( $field_name, $urcl_hide_fields, true ) && ur_string_to_bool( $required ) ) {
				$is_required = true;
			}
		}
		/**
		 * Filter the is field required.
		 *
		 * @param boolean $is_required The file name.
		 * @param array $field The field setting.
		 * @param array $form_data The form data.
		 */
		return apply_filters( 'user_registration_is_field_required', $is_required, $field, $form_data );
	}


	/**
	 * Validate update profile data submitted.
	 *
	 * @param [array] $form_fields Form Fields.
	 * @param array   $form_data Form Data.
	 * @param [int]   $form_id Form Id.
	 * @param [int]   $user_id User Id.
	 * @return void
	 */
	public function validate_update_profile( $form_fields, $form_data, $form_id, $user_id ) {
		$logger = ur_get_logger();
		$logger->info(
			sprintf( 'validate_update_profile started - form_id: %s, user_id: %s', $form_id, $user_id ),
			array( 'source' => 'ur-profile-validation' )
		);

		$form_field_data = ur_get_form_field_data( $form_id );

		$request_form_keys = array_map(
			function ( $el ) {
				return $el->field_name;
			},
			$form_data
		);

		$skippable_fields = $this->get_update_profile_validation_skippable_fields( $form_field_data );

		$form_key_list = wp_list_pluck( wp_list_pluck( $form_field_data, 'general_setting' ), 'field_name' );

		$required_fields = array_filter( array_diff( $form_key_list, $skippable_fields ) );

		$filteredfields = array_filter(
			$form_field_data,
			function ( $fields ) use ( $logger, $form_id ) {
				$fields = json_decode( json_encode( $fields ) );
				if ( ! is_object( $fields ) ) {
					$logger->warning(
						sprintf(
							'validate_update_profile - skipped non-object field entry (type: %s) for form_id: %s. Value: %s',
							gettype( $fields ),
							$form_id,
							wp_json_encode( $fields )
						),
						array( 'source' => 'ur-profile-validation' )
					);
					return false;
				}
				return property_exists( $fields, 'advance_setting' ) && is_object( $fields->advance_setting ) && property_exists( $fields->advance_setting, 'field_visibility' ) && 'reg_form' === $fields->advance_setting->field_visibility;
			}
		);

		$invisible_field_names = array_column( $filteredfields, 'general_setting' );//phpcs:ignore;
		$invisible_field_names = array_column( $invisible_field_names, 'field_name' ); //phpcs:ignore;
		$required_fields       = array_diff( $required_fields, $invisible_field_names );

		$this->add_hook( $form_field_data, $form_data );

		foreach ( $form_data as $data ) {

			if ( isset( $data->field_type ) && 'repeater' === $data->field_type ) {
				do_action(
					'user_registration_validate_edit_profile_repeater_fields',
					$data,
					$form_data,
					$form_id,
					$form_field_data,
					$form_fields
				);
				$required_fields = apply_filters( 'user_registration_missing_repeater_field_keys', $required_fields, $form_id );
			} else {
				user_registration_validate_edit_profile_form_field_data( $data, $form_data, $form_id, $form_field_data, $form_fields, $user_id );
			}
		}

		if ( array_diff( $required_fields, $request_form_keys ) ) {
			$missing = array_diff( $required_fields, $request_form_keys );
			$logger->error(
				sprintf(
					'validate_update_profile - missing required fields for form_id: %s, user_id: %s. Missing: %s',
					$form_id,
					$user_id,
					implode( ', ', $missing )
				),
				array( 'source' => 'ur-profile-validation' )
			);
			ur_add_notice( 'Some fields are missing in the submitted form. Please reload the page.', 'error' );
			return;
		}

		$logger->info(
			sprintf( 'validate_update_profile completed successfully - form_id: %s, user_id: %s', $form_id, $user_id ),
			array( 'source' => 'ur-profile-validation' )
		);
	}


	/**
	 * Returns a list of fields to skip validation for like Confirmation Fields,
	 * Woocommerce fields and Payment fields that are not submitted on profile update.
	 *
	 * @param [array] $form_data Form fields data.
	 * @return array
	 */
	public function get_update_profile_validation_skippable_fields( $form_data ) {
		$skippable_fields = array();

		$skippable_field_types = array(
			'user_pass',
			'user_confirm_email',
			'user_confirm_password',
			'profile_picture',
			'hidden',
			'invite_code',
			'billing_address_title',
			'billing_first_name',
			'billing_last_name',
			'billing_company',
			'billing_email',
			'billing_phone',
			'separate_shipping',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_country',
			'billing_postcode',
			'shipping_address_title',
			'shipping_first_name',
			'shipping_last_name',
			'shipping_company',
			'shipping_country',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_state',
			'shipping_postcode',
			'single_item',
			'multiple_choice',
			'range',
			'quantity_field',
			'total_field',
			'stripe_gateway',
			'captcha',
			'signature',
			'membership',
			'subscription_plan',
			'coupon',
		);

		$form_skippable_fields = array_filter(
			$form_data,
			function ( $field ) use ( $skippable_field_types ) {
				if ( in_array( $field->field_key, $skippable_field_types, true ) ) {

					if ( 'range' === $field->field_key && ( isset( $field->advance_setting->enable_payment_slider ) && ! ur_string_to_bool( $field->advance_setting->enable_payment_slider ) ) ) {
						return false;
					}

					return true;
				}

				return false;
			}
		);

		// Retrieves the hidden fields in profile update form.
		$field_visibility_skip_fields = array_filter(
			$form_data,
			function ( $field ) {
				if ( ! empty( $field->advance_setting->field_visibility ) && 'reg_form' === $field->advance_setting->field_visibility ) {
					return true;
				}

				return false;
			}
		);

		$field_visibility_skippable_fields = wp_list_pluck( wp_list_pluck( $field_visibility_skip_fields, 'general_setting' ), 'field_name' );
		$form_skippable_fields             = wp_list_pluck( wp_list_pluck( $form_skippable_fields, 'general_setting' ), 'field_name' );
		$skippable_fields                  = array_merge( $form_skippable_fields, $field_visibility_skippable_fields );

		/**
		 * Add fields to skip validation on update profile.
		 *
		 * @param [array] $skippable_fields Skippable fields array.
		 * @param [array] $form_data Form Fields data array.
		 *
		 * @since 3.0.4
		 */
		return apply_filters( 'user_registration_update_profile_validation_skip_fields', $skippable_fields, $form_data );
	}

	/**
	 * Run all validations and checks defined in the validation() method of field class on registration.
	 *
	 * @param [array]  $form_field_data Form Field data.
	 * @param [string] $field_name Field key.
	 * @param [array]  $form_key_list List of form field keys.
	 * @return void
	 */
	public function run_field_validations_on_registration( $form_field_data, $field_name, $form_key_list ) {

		if ( in_array( $field_name, $form_key_list, true ) ) {
			$form_data_index   = array_search( $field_name, $form_key_list, true );
			$single_form_field = $form_field_data[ $form_data_index ];
			$class_name        = ur_load_form_field_class( $single_form_field->field_key );
			$hook              = "user_registration_validate_{$single_form_field->field_key}";

			if ( class_exists( $class_name ) ) {

				/**
				 * Action to run form field validations.
				 */
				add_action(
					$hook,
					array(
						$class_name::get_instance(),
						'validation',
					),
					10,
					4
				);
			}
		}
	}

	/**
	 * Run all validations and checks defined in the validation() method of field class on profile update.
	 *
	 * @param [string] $single_field_key Field Key.
	 * @param [array]  $single_form_field Field Settings.
	 * @param [object] $data Field Data.
	 * @param [int]    $form_id Form Id.
	 * @return void
	 */
	public function run_field_validations_on_profile_update( $single_field_key, $single_form_field, $data, $form_id ) {

		// Bypass validations for these fields on update profile.
		if ( in_array( $single_field_key, array( 'user_login', 'user_email' ), true ) ) {
			return;
		}

		// Validate custom field validations of field class.
		$hook        = "user_registration_validate_{$single_field_key}";
		$filter_hook = $hook . '_message';

		/**
		 * Action validate single field.
		 *
		 * The dynamic portion of the hook name, $hook.
		 *
		 * @param array $single_form_field The form field.
		 * @param array $data The form data.
		 * @param string $filter_hook The dynamic filter hook.
		 * @param int $this->form_id The form ID.
		 */
		do_action( $hook, $single_form_field, $data, $filter_hook, $this->form_id );

		/**
		 * Filter the validate message.
		 *
		 * The dynamic portion of the hook name, $filter_hook.
		 * Default value is blank string.
		 */
		$response = apply_filters( $filter_hook, '' );

		if ( ! empty( $response ) ) {
			if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX && ! ur_option_checked( 'user_registration_ajax_form_submission_on_edit_profile', false ) ) {
				$response = array_values( $response );
				ur_add_notice( $response[0], 'error' );
			} else {
				ur_add_notice( $response, 'error' );
			}
			remove_all_filters( $filter_hook );
		}
	}
}

new UR_Form_Validation();
