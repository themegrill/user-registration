<?php
/**
 * User Registration Shortcodes.
 *
 * @class    UR_Shortcodes
 * @version  1.4.0
 * @package  UserRegistration/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UR_Shortcodes Class
 */
class UR_Shortcodes {

	public static $parts = false; // phpcs:ignore

	/**
	 * Init Shortcodes.
	 */
	public static function init() {
		$shortcodes = array(
			'user_registration_form'                => __CLASS__ . '::form', // change it to user_registration_form.
			'user_registration_my_account'          => __CLASS__ . '::my_account',
			'user_registration_login'               => __CLASS__ . '::login',
			'user_registration_lost_password'       => __CLASS__ . '::lost_password',
			'user_registration_reset_password_form' => __CLASS__ . '::reset_password_form',
			'user_registration_edit_profile'        => __CLASS__ . '::edit_profile',
			'user_registration_edit_password'       => __CLASS__ . '::edit_password',
		);
		add_filter( 'pre_do_shortcode_tag', array( UR_Shortcode_My_Account::class, 'pre_do_shortcode_tag' ), 10, 4 ); // phpcs:ignore

		foreach ( $shortcodes as $shortcode => $function ) {
			/**
			 * Applies filters to customize User Registration shortcode tags.
			 *
			 * The "{$shortcode}_shortcode_tag" filters allow developers to modify default shortcode tags
			 * for User Registration shortcodes like 'user_registration_form', 'user_registration_my_account', etc.
			 */
			add_shortcode( apply_filters( "{$shortcode}_shortcode_tag", $shortcode ), $function );
		}
	}

	/**
	 * Shortcode Wrapper.
	 *
	 * @param string[] $function Callback function.
	 * @param array    $atts (default: array()) Extra attributes.
	 * @param array    $wrapper Shortcode wrapper.
	 *
	 * @return string
	 */
	public static function shortcode_wrapper(
		$function,
		$atts = array(),
		$wrapper = array(
			'class'  => 'user-registration',
			'before' => null,
			'after'  => null,
		)
	) {
		ob_start();
		include_once UR_ABSPATH . 'includes/functions-ur-notice.php';
		$wrap_before = empty( $wrapper['before'] ) ? '<div id="user-registration" class="' . esc_attr( $wrapper['class'] ) . '">' : $wrapper['before'];
		echo wp_kses_post( $wrap_before );
		call_user_func( $function, $atts );
		$wrap_after = empty( $wrapper['after'] ) ? '</div>' : $wrapper['after'];
		echo wp_kses_post( $wrap_after );

		return ob_get_clean();
	}

	/**
	 * My account page shortcode.
	 *
	 * @param mixed $atts Extra attributes.
	 *
	 * @return string
	 */
	public static function my_account( $atts ) {
		/**
		 * Enqueues scripts and applies filters for User Registration 'my_account' shortcode.
		 *
		 * The 'user_registration_my_account_enqueue_scripts' action allows developers to enqueue scripts
		 * before rendering the 'my_account' shortcode. The 'user_registration_my_account_shortcode' filter
		 * lets developers customize shortcode attributes like class, before, and after.
		 */
		do_action( 'user_registration_my_account_enqueue_scripts', array(), 0 );
		wp_enqueue_script( 'ur-login' );

		return self::shortcode_wrapper(
			array( 'UR_Shortcode_My_Account', 'output' ),
			$atts,
			/**
			* Applies a filter to customize attributes for the User Registration 'my_account' shortcode.
			*
			* The 'user_registration_my_account_shortcode' filter allows developers to modify
			* shortcode attributes like class, before, and after before rendering the 'my_account' shortcode.
			*
			* @param array $default_attributes Default attributes for the 'my_account' shortcode.
			*/
			apply_filters(
				'user_registration_my_account_shortcode',
				array(
					'class'  => 'user-registration',
					'before' => null,
					'after'  => null,
				)
			)
		);
	}

	/**
	 * My account page shortcode.
	 *
	 * @param mixed $atts Extra attributes.
	 *
	 * @return string
	 */
	public static function login( $atts ) {
		/**
		 * Enqueues scripts and applies filters for User Registration 'login' shortcode.
		 *
		 * The 'user_registration_my_account_enqueue_scripts' action allows developers to enqueue scripts
		 * before rendering the 'login' shortcode. The 'user_registration_login_shortcode' filter
		 * lets developers customize shortcode attributes like class, before, and after.
		 */
		do_action( 'user_registration_my_account_enqueue_scripts', array(), 0 );
		wp_enqueue_script( 'ur-login' );

		return self::shortcode_wrapper(
			array( 'UR_Shortcode_Login', 'output' ),
			$atts,
			/**
			* Applies a filter to customize attributes for the User Registration 'login' shortcode.
			*
			* The 'user_registration_login_shortcode' filter allows developers to modify
			* shortcode attributes like class, before, and after before rendering the 'login' shortcode.
			*
			* @param array $default_attributes Default attributes for the 'login' shortcode.
			*/
			apply_filters(
				'user_registration_login_shortcode',
				array(
					'class'  => 'user-registration',
					'before' => null,
					'after'  => null,
				)
			)
		);
	}

	/**
	 * Lost password page shortcode.
	 *
	 * @param mixed $atts Extra attributes.
	 *
	 * @return string
	 */
	public static function lost_password( $atts ) {
		do_action( 'user_registration_my_account_enqueue_scripts', array(), 0 );

		return self::shortcode_wrapper( array( 'UR_Shortcode_My_Account', 'lost_password' ), $atts );
	}
	/**
	 * Reset password page shortcode.
	 *
	 * @param mixed $atts Extra attributes.
	 *
	 * @return string
	 */
	public static function reset_password_form( $atts ) {
		do_action( 'user_registration_my_account_enqueue_scripts', array(), 0 );
		return self::shortcode_wrapper( array( 'UR_Shortcode_My_Account', 'reset_password_form' ), $atts );
	}

	/**
	 * User Registration Edit password form shortcode.
	 *
	 * @param mixed $atts Extra attributes.
	 *
	 * @since 2.2.7
	 */
	public static function edit_password( $atts ) {
		return self::shortcode_wrapper( array( __CLASS__, 'render_edit_password' ), $atts );
	}

	/**
	 * Edit password page shortcode.
	 *
	 * @since 2.2.7
	 */
	public static function render_edit_password() {
		if ( is_user_logged_in() ) {
			include_once 'shortcodes/class-ur-shortcode-my-account.php';
			UR_Shortcode_My_Account::edit_password();
		} else {
			// If the user is not logged in, it triggers the 'user_registration_edit_password_shortcode' action.
			do_action( 'user_registration_edit_password_shortcode' );

			/* translators: %s - Link to login form. */
			echo wp_kses_post( apply_filters( 'user_registration_edit_password_shortcode_message', sprintf( __( 'Please Login to edit password. <a href="%s">Login Here?</a>', 'user-registration' ), ur_get_my_account_url() ) ) );
		}
	}

	/**
	 * User Registration Edit profile form shortcode.
	 *
	 * @param mixed $atts Extra attributes.
	 */
	public static function edit_profile( $atts ) {
		return self::shortcode_wrapper( array( __CLASS__, 'render_edit_profile' ), $atts );
	}

	/**
	 * Output for Edit-profile form .
	 */
	private static function render_edit_profile() {
		$user_id = get_current_user_id();
		$form_id = get_user_meta( $user_id, 'ur_form_id', true );
		/**
		 * Enqueues scripts for customizing 'my_account' shortcode rendering.
		 *
		 * The 'user_registration_my_account_enqueue_scripts' action allows developers
		 * to enqueue custom scripts before rendering the 'my_account' shortcode.
		 *
		 * @param array $empty_array Empty array passed for customization.
		 * @param int   $form_id      ID of the associated registration form.
		 */
		do_action( 'user_registration_my_account_enqueue_scripts', array(), $form_id );
		$has_flatpickr = ur_has_flatpickr_field( $form_id );

		if ( true === $has_flatpickr ) {
			wp_enqueue_style( 'flatpickr' );
			wp_enqueue_script( 'flatpickr' );
		}
		if ( ! is_user_logged_in() ) {
			$myaccount_page = get_post( get_option( 'user_registration_myaccount_page_id' ) );
			$matched        = 0;

			if ( ! empty( $myaccount_page ) ) {
				$matched = preg_match( '/\[user_registration_my_account(\s\S+){0,3}\]|\[user_registration_login(\s\S+){0,3}\]/', $myaccount_page->post_content );
				if ( 1 > absint( $matched ) ) {
					$matched = preg_match( '/\[woocommerce_my_account(\s\S+){0,3}\]/', $myaccount_page->post_content );
				}
				if ( 1 === $matched ) {
					$page_id = $myaccount_page->ID;
				}
			}

			/* translators: %s - Link to login form. */
			echo wp_kses_post( apply_filters( 'user_registration_logged_in_message', sprintf( __( 'Please Login to edit profile. <a href="%s">Login Here?</a>', 'user-registration' ), isset( $page_id ) ? get_permalink( $page_id ) : wp_login_url() ) ) );
		} else {
			include_once 'shortcodes/class-ur-shortcode-my-account.php';
			UR_Shortcode_My_Account::edit_profile();
		}
	}

	/**
	 * User Registration form shortcode.
	 *
	 * @param mixed $atts Extra attributes.
	 */
	public static function form( $atts ) {
		$check_user_state = isset( $atts['userState'] ) && 'logged_in' === $atts['userState'];

		if ( ! is_user_logged_in() && ! $check_user_state && ! ur_users_can_register() ) {
			return apply_filters( 'ur_register_pre_form_message', '<p class="alert" id="ur_register_pre_form_message">' . __( 'Registration is currently disabled.', 'user-registration' ) . '</p>' );
		}

		if ( is_user_logged_in() || $check_user_state ) {

			$is_membership_module_active    = ur_check_module_activation( 'membership' );
			$has_membership_checkout_intent = isset( $_GET['action'] ) && isset( $_GET['membership_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( $is_membership_module_active && is_user_logged_in() && $has_membership_checkout_intent ) {
				$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
				do_action( 'wp_enqueue_membership_scripts' );
				wp_enqueue_script( 'user-registration' );
				wp_register_script( 'user-registration-membership-frontend-script', UR()->plugin_url() . '/assets/js/modules/membership/frontend/user-registration-membership-frontend' . $suffix . '.js', array( 'jquery' ), UR_VERSION, true );
				wp_enqueue_script( 'user-registration-membership-frontend-script' );
				wp_register_style( 'user-registration-membership-frontend-style', UR()->plugin_url() . '/assets/css/modules/membership/user-registration-membership-frontend.css', array(), UR_VERSION );
				wp_register_style( 'user-registration-general', UR()->plugin_url() . '/assets/css/user-registration.css', array(), UR()->version );
				wp_enqueue_style( 'user-registration-membership-frontend-style' );
				wp_enqueue_style( 'user-registration-general' );

				$url_params = array( 'action', 'thank_you' );

				$has_all_params = ! array_diff( $url_params, array_keys( $_GET ) );

				if ( ! $has_all_params ) {
					global $wp;

					$current_user_capability = apply_filters( 'ur_registration_user_capability', 'create_users' );

					if ( ! current_user_can( $current_user_capability ) ) {
						$user_id      = get_current_user_id();
						$user         = get_user_by( 'ID', $user_id );
						$current_url  = home_url( add_query_arg( array(), $wp->request ) );
						$display_name = ! empty( $user->data->display_name ) ? $user->data->display_name : $user->data->user_email;
						/**
						 * Applies a filter to customize the pre-form message for user registration.
						 *
						 * @param string $default_message Default pre-form message.
						 */
						/* translators: 1: Link and username of user 2: Logout url */
						return apply_filters( 'ur_register_pre_form_message', '<p class="alert" id="ur_register_pre_form_message">' . sprintf( __( 'You are currently logged in as %1$1s. %2$2s', 'user-registration' ), '<a href="#" title="' . esc_attr( $display_name ) . '">' . esc_html( $display_name ) . '</a>', '<a href="' . wp_logout_url( $current_url ) . '" title="' . __( 'Log out of this account.', 'user-registration' ) . '">' . __( 'Logout', 'user-registration' ) . '  &raquo;</a>' ) . '</p>', $user_id );
					}
				} else {
					$membership_service = new WPEverest\URMembership\Admin\Services\MembershipService();

					$fetched_data = $membership_service->fetch_membership_details_from_intended_actions( $_GET );

					if ( isset( $fetched_data['status'] ) && $fetched_data['status'] ) {
						$user_id = get_current_user_id();
						$form_id = get_user_meta( $user_id, 'ur_form_id', true );

						if ( check_membership_field_in_form($form_id ) === false ) {
							$form_id = $atts['id'] ?? 0;
						}

						$form_fields = ur_get_form_fields( $form_id );

						foreach ( $form_fields as $field ) {
							add_filter(
								'user_registration_' . $field->field_key . '_frontend_form_data',
								function ( $default_data ) use ( $user_id, $field ) {
									if ( 'membership' !== $field->field_key && isset( $field->general_setting->field_name ) ) {
										$default_fields      = ur_get_user_table_fields();
										$default_meta_fields = ur_get_registered_user_meta_fields();

										$user_data = get_userdata( $user_id );

										if ( in_array( $field->field_key, $default_fields, true ) ) {
											$user_submitted_value = isset( $user_data->data->{ $field->field_key } ) ? $user_data->data->{ $field->field_key } : '';
										} elseif ( in_array( $field->field_key, $default_meta_fields, true ) ) {
											$user_submitted_value = get_user_meta( $user_id, $field->field_key, true );
										} else {
											$user_submitted_value = get_user_meta( $user_id, 'user_registration_' . $field->general_setting->field_name, true );
										}

										if ( 'user_pass' === $field->field_key || 'user_confirm_password' === $field->field_key || 'user_confirm_email' === $field->field_key ) {
											$default_data['form_data']['is_checkout'] = true;
										}

										$default_data['form_data']['default'] = $user_submitted_value;

										if ( ! empty( $user_submitted_value ) ) {
											$default_data['form_data']['custom_attributes']['disabled'] = 'disabled';
											$default_data['form_data']['custom_attributes']['readonly'] = 'readonly';
										}
										return $default_data;
									}
								}
							);
						}

						add_filter(
							'user_registration_handle_form_fields',
							function ( $grid_data ) use ( $user_id, $field ) {

								foreach ( $grid_data as $key => $data ) {
									$ignore_checkout = apply_filters(
										'user_registration_ignorable_checkout_fields',
										array(
											'user_pass',
											'user_confirm_password',
											'user_confirm_email',
											'profile_picture',
											'wysiwyg',
											'select2',
											'multi_select2',
											'range',
											'file',
										)
									);
									if ( in_array( $data->field_key, $ignore_checkout ) ) {
										unset( $grid_data[ $key ] );
									}
								}
								return $grid_data;
							}
						);

						add_filter(
							'user_registration_parts_data',
							function () {
								return false;
							},
							9999
						);

						add_filter(
							'user_registration_form_submit_btn_class',
							function ( $classes ) {
								$classes[] = 'urm-update-membership-button';
								return $classes;
							}
						);

						ob_start();
						self::render_form( $form_id );

						return ob_get_clean();
					} else {
						$message = isset( $fetched_data['message'] ) ? $fetched_data['message'] : esc_html__( 'Cannot fetch membership details. Please contact your site administrator.', 'user-registration' );

						return '<div id="user-registration" class="user-registration">' . $message . '</div>';
					}
				}
			} else {
				/**
				 * Applies a filter to customize the capability required for user registration.
				 *
				 * @param string $default_capability Default user capability.
				 */
				$current_user_capability = apply_filters( 'ur_registration_user_capability', 'create_users' );

				if ( ! current_user_can( $current_user_capability ) ) {
					global $wp;

					$user_ID      = get_current_user_id();
					$user         = get_user_by( 'ID', $user_ID );
					$current_url  = home_url( add_query_arg( array(), $wp->request ) );
					$display_name = ! empty( $user->data->display_name ) ? $user->data->display_name : $user->data->user_email;
					/**
					 * Applies a filter to customize the pre-form message for user registration.
					 *
					 * @param string $default_message Default pre-form message.
					 */
					/* translators: 1: Link and username of user 2: Logout url */
					return apply_filters( 'ur_register_pre_form_message', '<p class="alert" id="ur_register_pre_form_message">' . sprintf( __( 'You are currently logged in as %1$1s. %2$2s', 'user-registration' ), '<a href="#" title="' . esc_attr( $display_name ) . '">' . esc_html( $display_name ) . '</a>', '<a href="' . wp_logout_url( $current_url ) . '" title="' . __( 'Log out of this account.', 'user-registration' ) . '">' . __( 'Logout', 'user-registration' ) . '  &raquo;</a>' ) . '</p>', $user_ID );

				}
			}
		}

		$atts = shortcode_atts(
			array(
				'id' => '',
			),
			$atts,
			'user_registration_form'
		);
		/**
		 * Fires when rendering scripts for the 'user_registration_form' shortcode.
		 *
		 * The 'user_registration_form_shortcode_scripts' action allows developers
		 * to enqueue custom scripts or perform actions related to the 'user_registration_form' shortcode.
		 *
	 * @param array $atts Shortcode attributes passed for customization.
	 */
		do_action( 'user_registration_form_shortcode_scripts', $atts );

		ob_start();
		$form_id = ! empty( $atts['id'] ) ? $atts['id'] : get_option( 'user_registration_registration_form', true );

		self::render_form( $form_id );

		return ob_get_clean();
	}

	/**
	 * Output for registration form .
	 *
	 * @param int $form_id Form ID.
	 * @since 1.0.1 Recaptcha only
	 */
	private static function render_form( $form_id ) {
		$form_data_array = ( $form_id ) ? UR()->form->get_form( $form_id, array( 'content_only' => true ) ) : array();
		$form_json_data  = wp_json_encode( $form_data_array );

		$values = array(
			'form_id' => $form_id,
		);
		/**
		 * Applies a filter to process smart tags in the User Registration content.
		 *
		 * @param string $default_content Default content before processing smart tags.
		 * @param mixed  $form_json_data   Form JSON data.
		 * @param array  $values           User input values.
		 */
		$content         = apply_filters( 'user_registration_process_smart_tags', $form_json_data, $values, array() );
		$form_data_array = json_decode( $content );
		$form_row_ids    = '';
		$form_row_data   = array();

		if ( ! empty( $form_data_array ) ) {
			$form_row_ids  = get_post_meta( $form_id, 'user_registration_form_row_ids', true );
			$form_row_data = get_post_meta( $form_id, 'user_registration_form_row_data', true );
		}
		$form_row_ids_array = json_decode( $form_row_ids );

		if ( gettype( $form_row_ids_array ) != 'array' ) {
			$form_row_ids_array = array();
		}

		$is_field_exists           = false;
		$enable_strong_password    = ur_string_to_bool( ur_get_single_post_meta( $form_id, 'user_registration_form_setting_enable_strong_password' ) );
		$minimum_password_strength = ur_get_single_post_meta( $form_id, 'user_registration_form_setting_minimum_password_strength' );

		// Enqueue script.
		wp_enqueue_script( 'user-registration' );
		wp_enqueue_script( 'ur-form-validator' );
		wp_enqueue_script( 'ur-common' );

		/**
		 * Fires when enqueueing scripts for the User Registration plugin.
		 *
		 * The 'user_registration_enqueue_scripts' action allows developers to enqueue
		 * custom scripts or perform actions related to User Registration, specifically
		 * for a given registration form identified by $form_id.
		 *
		 * @param array $form_data_array Data array for the specific registration form.
		 * @param int   $form_id          ID of the associated registration form.
		 */
		do_action( 'user_registration_enqueue_scripts', $form_data_array, $form_id );

		$has_flatpickr = ur_has_flatpickr_field( $form_id );

		if ( true === $has_flatpickr ) {
			wp_enqueue_style( 'flatpickr' );
			wp_enqueue_script( 'flatpickr' );
		}

		if ( $enable_strong_password ) {
			wp_enqueue_script( 'ur-password-strength-meter' );
		}

		$recaptcha_enabled = ur_string_to_bool( ur_get_form_setting_by_key( $form_id, 'user_registration_form_setting_enable_recaptcha_support', false ) );
		$recaptcha_node    = ur_get_recaptcha_node( 'register', $recaptcha_enabled, $form_id );
		/**
		 * Applies a filter before rendering the User Registration registration form template.
		 *
		 * @param array $default_form_data_array Default form data array.
		 * @param int   $form_id                ID of the registration form.
		 */
		$form_data_array = apply_filters( 'user_registration_before_registration_form_template', $form_data_array, $form_id );

		/** Allow filter to return early if some condition is not meet.
		 *
		 * @since 4.1.0
		 */
		if ( ! apply_filters( 'user_registration_frontend_before_load', true, $form_data_array, $form_id ) ) {
			/**
			 * Fires when User Registration frontend form are not loaded.
			 *
			 * @param array $form_data_array Data array for the specific registration form.
			 * @param int   $form_id          ID of the associated registration form.
			 */
			do_action( 'user_registration_frontend_not_loaded', $form_data_array, $form_id );
			return;
		}
		/**
		 * Applies a filter to customize User Registration parts data.
		 *
		 * The 'user_registration_parts_data' filter allows developers to modify
		 * the parts data before processing it in the User Registration class.
		 *
		 * @param array $default_parts_data Default parts data.
		 * @param int   $form_id            ID of the registration form.
		 * @param array $form_data_array    Form data array.
		 */
		self::$parts = apply_filters( 'user_registration_parts_data', self::$parts, $form_id, $form_data_array );

		include_once UR_ABSPATH . 'includes/frontend/class-ur-frontend.php';

		ur_get_template(
			'form-registration.php',
			array(
				'form_data_array'           => $form_data_array,
				'is_field_exists'           => $is_field_exists,
				'form_id'                   => $form_id,
				'enable_strong_password'    => $enable_strong_password,
				'minimum_password_strength' => $minimum_password_strength,
				'recaptcha_node'            => $recaptcha_node,
				'parts'                     => self::$parts,
				'row_ids'                   => $form_row_ids_array,
				'form_row_data'             => $form_row_data,
				'recaptcha_enabled'         => $recaptcha_enabled,
			)
		);
	}
	/**
	 * Check the redirection url is valid url or slug.
	 *
	 * @param string $redirect_url redirection url.
	 */
	public static function check_is_valid_redirect_url( $redirect_url ) {
		if ( filter_var( $redirect_url, FILTER_VALIDATE_URL ) === false ) {
			$all_page_slug = ur_get_all_page_slugs();
			if ( in_array( $redirect_url, $all_page_slug, true ) ) {
				$redirect_url = site_url( $redirect_url );
			} elseif ( '' === $redirect_url ) {
				$redirect_url;
			} else {
				$redirect_url = home_url();
			}
		}
		return $redirect_url;
	}
}
