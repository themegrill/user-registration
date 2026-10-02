<?php
/**
 * UserRegistration Template
 *
 * Functions for the templating system.
 *
 * @package  UserRegistration/Functions
 * @version  1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

add_action( 'template_redirect', 'ur_template_redirect' );


/**
 * Redirect after logout.
 * Handle redirects before content is output - hooked into template_redirect so is_page works.
 */
function ur_template_redirect() {
	global $wp;

	if ( ( isset( $wp->query_vars['user-logout'] )
		|| ( isset( $wp->query_vars['name'] ) && 'user-logout' === $wp->query_vars['name'] ) )
			&& ! empty( $_REQUEST['_wpnonce'] )
			&& wp_verify_nonce( $_REQUEST['_wpnonce'], 'user-logout' ) ) { //PHPCS:ignore;

		$redirect_url = str_replace( '/user-logout', '', $wp->request );

		if ( isset( $_GET['redirect_to_on_logout'] ) && isset( $_GET['redirect_sig'] ) ) {
			$requested_redirect = esc_url_raw( wp_unslash( $_GET['redirect_to_on_logout'] ) );
			$provided_sig       = sanitize_text_field( wp_unslash( $_GET['redirect_sig'] ) );

			if ( ur_verify_redirect_signature( $requested_redirect, $provided_sig ) ) {
				wp_logout();
				wp_redirect( $requested_redirect ); //PHPCS:ignore;
			} else {
				// Signature invalid or missing — attacker-crafted URL, reject it.
				wp_logout();
				wp_safe_redirect( home_url() );
			}
			exit;
		}

		$redirect_url = apply_filters( 'user_registration_redirect_after_logout', $redirect_url );
		wp_logout();
		wp_safe_redirect( ur_get_page_permalink( $redirect_url ) );
		exit;

	} elseif ( isset( $wp->query_vars['user-logout'] ) && 'true' === $wp->query_vars['user-logout'] ) {
		/**
		 * Filter the redirect after logout url.
		 *
		 * @param string $redirect_url The redirect url.
		 */
		$redirect_url = apply_filters( 'user_registration_redirect_after_logout', esc_url_raw( ur_get_page_permalink( 'user-logout' ) ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}
}

/**
 * Generate a signed logout redirect URL.
 *
 * Use this in your code wherever you build the logout link.
 * The signature is an HMAC-SHA256 of the redirect URL using
 * WordPress's AUTH_KEY as the secret — never exposed to the client.
 *
 * Example:
 *   $logout_url = ur_generate_signed_logout_url( 'https://patrowl.io/dashboard' );
 *   // Produces:
 *   // https://example.com/?user-logout=1
 *   //   &redirect_to_on_logout=https%3A%2F%2Fpatrowl.io%2Fdashboard
 *   //   &redirect_sig=<hmac>
 *   //   &_wpnonce=<nonce>
 *
 * @param string $redirect_url The external (or internal) URL to redirect to after logout.
 * @return string Signed logout URL.
 */
function ur_generate_signed_logout_url( $redirect_url ) {
	$sig = ur_generate_redirect_signature( $redirect_url );

	return add_query_arg(
		array(
			'user-logout'           => '1',
			'redirect_to_on_logout' => rawurlencode( $redirect_url ),
			'redirect_sig'          => $sig,
			'_wpnonce'              => wp_create_nonce( 'user-logout' ),
		),
		ur_get_page_permalink( 'myaccount' )
	);
}

/**
 * Generate an HMAC-SHA256 signature for a redirect URL.
 *
 * @param string $redirect_url The URL to sign.
 * @return string Hex-encoded HMAC signature.
 */
function ur_generate_redirect_signature( $redirect_url ) {
	return hash_hmac( 'sha256', $redirect_url, wp_salt( 'auth' ) );
}

/**
 * Verify that a redirect URL matches its provided signature.
 *
 * Uses hash_equals() for timing-safe comparison to prevent
 * timing side-channel attacks.
 *
 * @param string $redirect_url The URL to verify.
 * @param string $provided_sig The signature provided in the request.
 * @return bool True if signature is valid, false otherwise.
 */
function ur_verify_redirect_signature( $redirect_url, $provided_sig ) {
	$expected_sig = ur_generate_redirect_signature( $redirect_url );
	return hash_equals( $expected_sig, $provided_sig );
}

if ( ! function_exists( 'ur_get_form_redirect_url' ) ) {
	/**
	 * Returns redirect url setup in form settings.
	 *
	 * @param integer $form_id Form Id.
	 * @param string  $redirect_url Fallback Url.
	 * @param boolean $maybe_translate Whether to translate url.
	 *
	 * @return string
	 */
	function ur_get_form_redirect_url( $form_id = 0, $redirect_url = '', $maybe_translate = true ) {

		$login_option      = ur_get_form_setting_by_key( $form_id, 'user_registration_form_setting_login_options' );
		$paypal_is_enabled = ur_string_to_bool( ur_get_single_post_meta( $form_id, 'user_registration_enable_paypal_standard', false ) );

		if ( ! $paypal_is_enabled ) {

			if ( empty( $redirect_url ) ) {
				// Getting redirect options from global settings for backward compatibility.
				$redirect_url = get_option( 'user_registration_general_setting_redirect_options', $redirect_url );
			}

			if ( ! empty( $form_id ) ) {

				$redirect_option = ur_get_single_post_meta( $form_id, 'user_registration_form_setting_redirect_after_registration', ur_get_default_redirect_after_registration( $form_id ) );

				switch ( $redirect_option ) {
					case 'no-redirection':
						$redirect_url = '';
						break;

					case 'internal-page':
						$selected_page = ur_get_single_post_meta( $form_id, 'user_registration_form_setting_redirect_page', get_option( 'user_registration_thank_you_page_id', '' ) );

						if ( ! empty( $selected_page ) && 'no-redirection' !== $selected_page ) {
							$page_url     = get_permalink( ur_get_translated_page_id( $selected_page ) );
							$redirect_url = $page_url;
						} else {
							$redirect_url = '';
						}

						break;

					case 'external-url':
						$external_url = ur_get_single_post_meta( $form_id, 'user_registration_form_setting_redirect_options', $redirect_url );
						$redirect_url = $external_url;

						break;
					case 'previous-page':
						$redirect_url = apply_filters( 'user_registration_redirection_back_to_previous_page_url', isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : $redirect_url, $form_id );
						break;

					default:
				}

				if ( empty( $redirect_url ) && 'auto_login' === $login_option ) {
					/**
					 * Filter to modify the auto login redirection.
					 *
					 * @param string $my_account_page_url The my account page url.
					 */
					$redirect_url = apply_filters( 'user_registration_auto_login_redirection', ur_get_my_account_url() );
				}
			}

			if ( $maybe_translate ) {
				$redirect_url = ur_string_translation( $form_id, 'user_registration_form_setting_redirect_options', $redirect_url );
			}
		}

		/**
		 * Filter the form redirect url.
		 * It depends on the form.
		 *
		 * @param string $redirect_url The redirect url.
		 * @param int $form_id The form ID.
		 */
		return apply_filters( 'user_registration_form_redirect_url', $redirect_url, $form_id );
	}
}

/**
 * Add body classes for UR pages.
 *
 * @param array $classes Classes.
 *
 * @return array
 */
function ur_body_class( $classes ) {
	$classes = (array) $classes;

	$classes[] = 'user-registration-page';
	if ( is_ur_account_page() ) {
		$classes[] = 'user-registration-account';
	}
	foreach ( UR()->query->query_vars as $key => $value ) {
		if ( is_ur_endpoint_url( $key ) ) {
			$classes[] = 'user-registration-' . sanitize_html_class( $key );
		}
	}

	return array_unique( $classes );
}

/**
 * Admin body class.
 *
 * @param string $classes Classes.
 *
 * @return string
 */
function ur_admin_body_class( $classes ) {
	return $classes . ' user-registration-page';
}

if ( ! function_exists( 'user_registration_form_field' ) ) {

	/**
	 * Outputs a form fields on frontend.
	 *
	 * @param string $key Key.
	 * @param mixed  $args Arguments.
	 * @param string $value Value (default: null).
	 * @param string $current_row Current Row (default: empty).
	 * @param bool   $is_edit Is edit flag.
	 *
	 * @return string
	 */
	function user_registration_form_field( $key, $args, $value = null, $current_row = '', $is_edit = false ) {
		if ( isset( $args['is_checkout'] ) && $args['is_checkout'] ) {
			return;
		}

		/* Conditional Logic codes */
		$rules                      = array();
		$rules['conditional_rules'] = isset( $args['conditional_rules'] ) ? $args['conditional_rules'] : '';
		$rules['logic_gate']        = isset( $args['logic_gate'] ) ? $args['logic_gate'] : '';
		$rules['rules']             = isset( $args['rules'] ) ? $args['rules'] : array();
		$rules['required']          = isset( $args['required'] ) ? $args['required'] : '';

		foreach ( $rules['rules'] as $rules_key => $rule ) {
			if ( empty( $rule['field'] ) ) {
				unset( $rules['rules'][ $rules_key ] );
			}
		}

		$required = '';

		$rules['rules'] = array_values( $rules['rules'] );

		$rules = ( ! empty( $rules['rules'] ) && isset( $args['enable_conditional_logic'] ) ) ? wp_json_encode( $rules ) : '';
		/*Conditonal Logic codes end*/

		$defaults = array(
			'type'              => 'text',
			'label'             => '',
			'description'       => '',
			'placeholder'       => '',
			'size'              => false,
			'min'               => false,
			'max'               => false,
			'required'          => false,
			'autocomplete'      => false,
			'id'                => $key,
			'class'             => array(),
			'input_class'       => array(),
			'return'            => false,
			'options'           => array(),
			'custom_attributes' => array(),
			'validate'          => array(),
			'default'           => '',
			'autofocus'         => '',
			'priority'          => '',
		);

		$args = wp_parse_args( $args, $defaults );
		/**
		 * Filters the arguments for a user registration form field.
		 *
		 * The 'user_registration_form_field_args' filter allows developers to modify
		 * the arguments (args) for a specific form field during the user registration
		 * process. It provides an opportunity to customize the field arguments based on
		 * the original args, field key, and field value.
		 *
		 * @param array $args The original arguments for the form field.
		 * @param string $key The key identifying the form field.
		 * @param mixed $value The value of the form field.
		 */
		$args = apply_filters( 'user_registration_form_field_args', $args, $key, $value );

		if ( true === ur_string_to_bool( $args['required'] ) ) {
			$args['class'][]                       = 'validate-required';
			$args['custom_attributes']['required'] = 'required';
			$required                              = ' <abbr class="required" title="' . esc_attr__( 'required', 'user-registration' ) . '">*</abbr>';
		} else {
			$args['required'] = '';
		}

		if ( ( is_null( $value ) || empty( $value ) ) && ! is_numeric( $value ) ) {
			$value = $args['default'];
		}

		// Custom attribute handling.
		$custom_attributes         = array();
		$args['custom_attributes'] = array_filter( (array) $args['custom_attributes'] );

		if ( $args['size'] ) {
			$args['custom_attributes']['maxlength'] = absint( $args['size'] );
		}

		if ( ! empty( $args['min'] ) || '0' === $args['min'] ) {
			$args['custom_attributes']['min'] = $args['min'];
		}

		if ( isset( $args['min-characters'] ) ) {
			if ( ! empty( $args['min-characters'] ) || '0' === $args['min-characters'] ) {
				$args['custom_attributes']['minlength'] = $args['min-characters'];
			}
		}

		if ( isset( $args['max-characters'] ) ) {
			if ( ! empty( $args['max-characters'] ) || '0' === $args['max-characters'] ) {
				$args['custom_attributes']['maxlength'] = $args['max-characters'];
			}
		}

		if ( isset( $args['min-words'] ) ) {
			if ( ! empty( $args['min-words'] ) || '0' === $args['min-words'] ) {
				$args['custom_attributes']['data-min-words'] = $args['min-words'];
			}
		}

		if ( isset( $args['max-words'] ) ) {
			if ( ! empty( $args['max-words'] ) || '0' === $args['max-words'] ) {
				$args['custom_attributes']['max-words'] = $args['max-words'];
			}
		}

		if ( ! empty( $args['max'] ) || '0' === $args['max'] ) {
			$args['custom_attributes']['max'] = $args['max'];
		}

		if ( ! empty( $args['step'] ) ) {
			$args['custom_attributes']['step'] = $args['step'];
		}

		if ( ! empty( $args['autocomplete'] ) ) {
			$args['custom_attributes']['autocomplete'] = $args['autocomplete'];
		}

		if ( true === $args['autofocus'] ) {
			$args['custom_attributes']['autofocus'] = 'autofocus';
		}

		if ( isset( $args['default_value'] ) && ! empty( $args['default_value'] ) ) {
			$args['custom_attributes']['data-default'] = is_array( $args['default_value'] ) ? implode( ', ', $args['default_value'] ) : $args['default_value'];
		}

		if ( isset( $args['default'] ) && ! empty( $args['default'] ) ) {
			$args['custom_attributes']['data-default'] = $args['default'];
		}

		if ( isset( $args['field_key'] ) && 'country' === $args['field_key'] ) {
			if ( isset( $args['default'] ) && ! empty( $args['default'] ) ) {
				$is_json = preg_match( '/^\{.*\}$/s', $args['default'] ) ? true : false;
				if ( $is_json ) {
					$args['default'] = json_decode( $args['default'], true );
				}
				$args['custom_attributes']['data-default'] = is_array( $args['default'] ) ? $args['default']['country'] : $args['default'];
			}
		}

		if ( ! empty( $args['custom_attributes'] ) && is_array( $args['custom_attributes'] ) ) {
			foreach ( $args['custom_attributes'] as $attribute => $attribute_value ) {
				$attribute_value     = is_array( $attribute_value ) ? json_encode( $attribute_value ) : $attribute_value;
				$custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
			}
		}

		if ( ! empty( $args['validate'] ) ) {
			foreach ( $args['validate'] as $validate ) {
				$args['class'][] = 'validate-' . $validate;
			}
		}

		$tooltip_html = '';

		if ( isset( $args['tooltip'] ) && ur_string_to_bool( $args['tooltip'] ) ) {
			$tooltip_html = ur_help_tip( $args['tooltip_message'], false, 'ur-portal-tooltip' );
		} elseif ( isset( $args['tip'] ) ) {
			$tooltip_html = ur_help_tip( $args['tip'], false, 'user-registration-help-tip tooltipstered' );
		}

		$cl_html = '';

		if ( isset( $args['enable_conditional_logic'] ) && true === $args['enable_conditional_logic'] ) {
			$cl_map  = isset( $args['cl_map'] ) ? $args['cl_map'] : '';
			$cl_html = sprintf( 'data-conditional-logic-enabled="1" data-conditional-logic-map="%s"', esc_attr( $cl_map ) );
		}

		$field           = '';
		$label_id        = $args['id'];
		$sort            = $args['priority'] ? $args['priority'] : '';
		$field_container = '<div class="form-row %1$s" id="%2$s" data-priority="' . esc_attr( $sort ) . '" ' . $cl_html . '>%3$s</div>';
		$class           = '';
		if ( ! is_admin() ) {
			$form_id = isset( $args['form_id'] ) ? $args['form_id'] : '';
			/**
			 * Filters the enabled class for the icon associated with a user registration form field.
			 *
			 * The 'user_registration_field_icon_enabled_class' filter allows developers to modify
			 * the class name representing the enabled state of the icon associated with a form field.
			 * It provides an opportunity to customize the enabled class based on the original class
			 * and the form ID.
			 *
			 * @param string $class The original class representing the enabled state of the icon.
			 * @param int $form_id The ID of the user registration form.
			 */
			$class = apply_filters( 'user_registration_field_icon_enabled_class', $class, $form_id );
		}

		switch ( $args['type'] ) {

			case 'title':
				$field .= '<h4>' . esc_html( $args['title'] ) . '</h4>';
				break;

			case 'textarea':
				if ( ! isset( $args['icon'] ) && empty( $args['icon'] ) ) {
					$args['input_class'][] = 'without_icon';
				}

				$field .= '<textarea style="margin-bottom:0px;" data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="input-text ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '" ' . ( empty( $args['custom_attributes']['rows'] ) ? ' rows="2"' : '' ) . ( empty( $args['custom_attributes']['cols'] ) ? ' cols="5"' : '' ) . implode( ' ', $custom_attributes ) . '>' . esc_textarea( $value ) . '</textarea>';
				$field .= '<div style="text-align: right; font-size:14px; color:#737373; margin-top:0px;"> <div class="ur-input-count" data-count-type="' . ( isset( $args['max-words'] ) ? 'words' : 'characters' ) . '" style="display: inline-block; margin-right: 1px;">0</div>&nbsp;';
				$field .= '<div style="display: inline-block;">' . ( isset( $args['max-words'] ) ? '/' . $args['max-words'] . ' ' . __( 'words', 'user-registration' ) : ( isset( $args['max-characters'] ) ? '/' . $args['max-characters'] . ' ' . __( 'characters', 'user-registration' ) : ' ' . __( 'characters', 'user-registration' ) ) );
				$field .= '</div></div>';
				break;

			case 'checkbox':
				$field_key         = isset( $args['field_key'] ) ? $args['field_key'] : '';
				$default_value     = isset( $args['default_value'] ) ? $args['default_value'] : '';    // Backward compatibility. Modified since 1.5.7.
				$default           = ! empty( $value ) ? $value : $default_value;
				$select_all        = isset( $args['select_all'] ) ? ur_string_to_bool( $args['select_all'] ) : false;
				$options           = isset( $args['options'] ) ? $args['options'] : array();
				$image_options     = isset( $args['image_options'] ) ? $args['image_options'] : array();
				$choice_limit      = isset( $args['choice_limit'] ) ? $args['choice_limit'] : '';
				$choice_limit_attr = '';

				if ( '' !== $choice_limit ) {
					$choice_limit_attr = 'data-choice-limit="' . $choice_limit . '"';
				}
				if ( isset( $args['image_choice'] ) && ur_string_to_bool( $args['image_choice'] ) && isset( $image_options ) && array_filter( $image_options ) ) {
					if ( ! empty( $default ) ) {
						$default = ( is_serialized( $default ) ) ? unserialize( $default, array( 'allowed_classes' => false ) ) : $default; //phpcs:ignore;
					}
					$choices = isset( $image_options ) ? $image_options : array();

					$field  = '<label class="ur-label" ' . implode( ' ', $custom_attributes ) . '>';
					$field .= $args['label'] . $required . $tooltip_html . '</label>';

					$checkbox_start = 0;

					$field .= '<ul ' . $choice_limit_attr . 'class="user-registration-image-options">';

					if ( $select_all ) {
						$field .= '<li class="ur-checkbox-list"><input type="checkbox" id="checkall" class="ur-input-checkbox"  data-check="' . esc_attr( $key ) . '"/>';
						$field .= '<label class="ur-checkbox-label">  ' . esc_html__( 'Select All', 'user-registration' ) . '</label></li>';
					}
					foreach ( $choices as $choice_index => $choice ) {
						$choice_label = is_array( $choice ) ? $choice['label'] : $choice->label;
						$choice_image = is_array( $choice ) ? $choice['image'] : $choice->image;
						$value        = '';

						if ( '' !== $default ) {
							$default = 'string' === gettype( $default ) ? json_decode( $default ) : $default;

							if ( is_array( $default ) && in_array( ur_sanitize_tooltip( trim( $choice_index ) ), $default ) ) {
								$value = 'checked="checked"';
							} elseif ( $default === $choice_index ) {
								$value = 'checked="checked"';
							}
						}
						$field       .= '<li class="ur-checkbox-list">';
						$choice_index = ur_sanitize_tooltip( $choice_index );
						$field       .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '[]" ' . implode( ' ', $custom_attributes ) . ' data-value="' . esc_attr( $choice_index ) . '" type="' . esc_attr( $args['type'] ) . '" class="input-checkbox ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '[]" id="' . esc_attr( $args['id'] ) . '_' . esc_attr( $choice_index ) . '" value="' . esc_attr( $choice_index ) . '" ' . esc_attr( $value ) . '/>';
						$field       .= '<label class="ur-checkbox-label" for="' . esc_attr( $args['id'] ) . '_' . esc_attr( $choice_index ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '">';
						if ( ! empty( $choice_image ) ) {
							$field .= '<span class="user-registration-image-choice">';
							$field .= '<img src="' . esc_url( $choice_image ) . '" alt="' . esc_attr( trim( $choice_label ) ) . '" width="200px">';
							$field .= '</span>';
						}
						$field .= trim( $choice_label ) . '</label> </li>';
						++$checkbox_start;
					}
					$field .= '</ul>';
				} elseif ( isset( $options ) && array_filter( $options ) ) {
					if ( ! empty( $default ) ) {
						$default = ( is_serialized( $default ) ) ? unserialize( $default, array( 'allowed_classes' => false ) ) : ( is_array( $default ) ? $default : ( empty( json_decode( $default ) ) ? $default : json_decode( $default ) ) ); //phpcs:ignore;
					}

					$choices = isset( $options ) ? $options : array();

					$field  = '<label class="ur-label" ' . implode( ' ', $custom_attributes ) . '>';
					$field .= $args['label'] . $required . $tooltip_html . '</label>';

					$checkbox_start = 0;

					$field .= '<ul ' . $choice_limit_attr . '>';

					if ( $select_all ) {
						$field .= '<li class="ur-checkbox-list"><input type="checkbox" id="checkall" class="ur-input-checkbox"  data-check="' . esc_attr( $key ) . '"/>';
						$field .= '<label class="ur-checkbox-label">  ' . esc_html__( 'Select All', 'user-registration' ) . '</label></li>';
					}
					foreach ( $choices as $choice_index => $choice ) {

						$value = '';
						if ( '' !== $default ) {
							if ( is_array( $default ) && in_array( html_entity_decode( ur_sanitize_tooltip( trim( $choice_index ) ) ), $default ) ) {
								$value = 'checked="checked"';
							} elseif ( $default === $choice_index ) {
								$value = 'checked="checked"';
							}
						}
						$field       .= '<li class="ur-checkbox-list">';
						$choice_index = ur_sanitize_tooltip( $choice_index );

						$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '[]" ' . implode( ' ', $custom_attributes ) . ' data-value="' . esc_attr( $choice_index ) . '" type="' . esc_attr( $args['type'] ) . '" class="input-checkbox ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '[]" id="' . esc_attr( $args['id'] ) . '_' . esc_attr( $choice_index ) . '" value="' . esc_attr( $choice_index ) . '" ' . esc_attr( $value ) . '/>';
						$field .= '<label class="ur-checkbox-label" for="' . esc_attr( $args['id'] ) . '_' . esc_attr( $choice_index ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '">' . trim( $choice ) . '</label> </li>';

						++$checkbox_start;
					}
					$field .= '</ul>';
				} else {
					$field = '<label class="ur-label checkbox" ' . implode( ' ', $custom_attributes ) . '>
							<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" ' . implode( ' ', $custom_attributes ) . ' data-value="' . esc_attr( $value ) . '" type="' . esc_attr( $args['type'] ) . '" class="input-checkbox ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '" id="' . esc_attr( $args['id'] ) . '" value="1" ' . checked( $value, 1, false ) . ' /> '
							. $args['label'] . $required . $tooltip_html . '</label>';
				}
				break;
			case 'toggle':
				$default_value     = isset( $args['default_value'] ) ? $args['default_value'] : '';    // Backward compatibility. Modified since 1.5.7.
				$value             = ! empty( $value ) ? $value : $default_value;
				$select_all        = isset( $args['select_all'] ) ? ur_string_to_bool( $args['select_all'] ) : false;
				$options           = isset( $args['options'] ) ? $args['options'] : ( $args['choices'] ? $args['choices'] : array() ); // $args['choices'] for backward compatibility. Modified since 1.5.7.
				$choice_limit      = isset( $args['choice_limit'] ) ? $args['choice_limit'] : '';
				$choice_limit_attr = '';

				$field  = '<div class="ur-toggle-section ur-form-builder-toggle">';
				$field .= '<span class="user-registration-toggle-form">';
				$field .= '<input data-id="' . esc_attr( $key ) . '" ' . implode( ' ', $custom_attributes ) . ' data-value="' . ur_string_to_bool( $value ) . '" type="checkbox" class="input-checkbox ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" value="1" ' . checked( ur_string_to_bool( $value ), 1, false ) . ' />';
				$field .= '<span class="slider round"></span>';
				$field .= '</span>';
				$field .= '<label class="ur-label checkbox" for="' . esc_attr( $key ) . '">' . $args['label'] . wp_kses_post( $tooltip_html ) . '</label>';
				$field .= '</div>';
				break;
			case 'radio-group':
				$default_value = isset( $args['default_value'] ) ? $args['default_value'] : '';    // Backward compatibility. Modified since 1.5.7.
				$default       = ! empty( $value ) ? $value : $default_value;
				$select_all    = isset( $args['select_all'] ) ? ur_string_to_bool( $args['select_all'] ) : false;
				$options       = isset( $args['options'] ) ? $args['options'] : ( $args['choices'] ? $args['choices'] : array() ); // $args['choices'] for backward compatibility. Modified since 1.5.7.
				$choice_limit  = isset( $args['choice_limit'] ) ? $args['choice_limit'] : '';

				if ( ! empty( $args['options'] ) ) {

					$field .= '<ul class="ur-radio-group-list">';
					foreach ( $args['options'] as $option_index => $option_text ) {
						$class = str_replace( ' ', '-', strtolower( $option_text ) );

						$field .= '<li class="ur-radio-group-list--item  ' . $class . ( trim( $option_index ) === $value ? ' active' : '' ) . '">';

						$checked = '';

						if ( '' !== $value ) {
							$checked = checked( $value, trim( $option_index ), false );
						}

						$field .= '<label for="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_text ) . '" class="radio">';

						$field .= wp_kses(
							trim( $option_text ),
							array(
								'a'    => array(
									'href'  => array(),
									'title' => array(),
								),
								'span' => array(),
							)
						);

						$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="radio" class="input-radio ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" value="' . esc_attr( trim( $option_index ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_text ) . '" ' . implode( ' ', $custom_attributes ) . ' / ' . $checked . ' /> ';
						$field .= '</label>';

						$field .= '</li>';
					}
					$field .= '</ul>';
				}
				break;
			case 'password':
				$extra_params_key = str_replace( 'user_registration_', 'ur_', $key ) . '_params';
				$extra_params     = json_decode( get_user_meta( get_current_user_id(), $extra_params_key, true ) );
				$field           .= ' <span class="input-wrapper"> ';

				if ( empty( $extra_params ) ) {
					$field_container = '<div class="form-row %1$s hide_show_password" id="%2$s" data-priority="' . esc_attr( $sort ) . '">%3$s</div>';
					$field          .= '<span class="password-input-group input-form-field-icons">';
					$field          .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="' . esc_attr( $args['type'] ) . '" class="input-text ' . $class . ' input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" ' . implode( ' ', $custom_attributes ) . ' />';
					if ( ur_option_checked( 'user_registration_login_option_hide_show_password', false ) && ! $is_edit ) {
						$field .= '<a href="javaScript:void(0)" class="password_preview dashicons dashicons-hidden" title=" Show password "></a>';
					}
					$field .= '</span>';
				} else {
					$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="' . esc_attr( $args['type'] ) . '" class="input-text  ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" ' . implode( ' ', $custom_attributes ) . ' />';
				}

				if ( ! is_admin() ) {
					/**
					 * Filters the icon markup for a user registration form field.
					 *
					 * The 'user_registration_field_icon' filter allows developers to modify
					 * the icon markup associated with a specific form field during the user
					 * registration process. It provides an opportunity to customize the icon
					 * based on the original icon markup, form ID, and field arguments.
					 *
					 * @param string $field The original icon markup associated with the form field.
					 * @param int $form_id The ID of the user registration form.
					 * @param array $args The arguments for the form field.
					 */
					$field  = apply_filters( 'user_registration_field_icon', $field, $form_id, $args );
					$field .= ' </span> ';
				}
				break;

			case 'text':
			case 'email':
			case 'tel':
			case 'number':
			case 'url':
			case 'file':
			case 'timepicker':
				$extra_params_key    = str_replace( 'user_registration_', 'ur_', $key ) . '_params';
				$extra_params        = json_decode( get_user_meta( get_current_user_id(), $extra_params_key, true ) );
				$current_time        = isset( $args['current_time'] ) ? $args['current_time'] : '';
				$time_interval       = isset( $args['time_interval'] ) ? $args['time_interval'] : '';
				$time_format         = isset( $args['time_format'] ) ? $args['time_format'] : '';
				$time_range          = isset( $args['time_range'] ) ? $args['time_range'] : '';
				$time_min            = isset( $args['time_min'] ) ? $args['time_min'] : '';
				$time_max            = isset( $args['time_max'] ) ? $args['time_max'] : '';
				$username_length     = isset( $args['username_length'] ) ? $args['username_length'] : '';
				$username_character  = isset( $args['username_character'] ) ? $args['username_character'] : '';
				$time_slot_booking   = isset( $args['enable_time_slot_booking'] ) ? $args['enable_time_slot_booking'] : '';
				$target_date_field   = isset( $args['target_date_field'] ) ? isset( $args['target_date_field'] ) : '';
				$enable_calculations = $args['enable_calculations'] ?? '';
				$calculation_formula = $args['calculation_formula'] ?? '';
				$decimal_places      = $args['decimal_places'] ?? '';
				$attr                = '';

				if ( '' !== $username_length ) {
					$attr .= 'data-username-length="' . $username_length . '"';
				}

				if ( $username_character ) {
					$attr .= 'data-username-character="' . $username_character . '"';
				}

				if ( '' !== $time_interval ) {
					$attr .= 'data-time-interval="' . $time_interval . '"';
				}

				if ( '' !== $time_format ) {
					$attr .= 'data-time-format="' . $time_format . '"';
				}

				if ( '' !== $time_min ) {
					$attr .= 'data-time-min="' . $time_min . '"';
				}

				if ( '' !== $time_max ) {
					$attr .= 'data-time-max="' . $time_max . '"';
				}

				if ( $time_range ) {
					$attr .= 'data-time-range="' . $time_range . '"';
				}

				if ( $current_time ) {
					$attr .= 'data-current-time="' . $current_time . '"';
				}
				if ( '' !== $enable_calculations && $enable_calculations ) {
					$attr .= 'readonly data-decimal-places="' . esc_attr( $decimal_places ) . '" data-calculation-formula="' . esc_attr( $calculation_formula ) . '"';
				}
				if ( ur_string_to_bool( $time_slot_booking ) ) {
					$target_date_field = isset( $args['target_date_field'] ) ? $args['target_date_field'] : '';

					$attr  .= 'data-enable-time-slot-booking="' . $time_slot_booking . '"';
					$attr  .= 'data-target-date-field="' . $target_date_field . '"';
					$class .= ' time-slot-booking ';
				}

				$field .= ' <span class="input-wrapper"> ';
				if ( isset( $args['autocomplete_address'] ) && ur_string_to_bool( $args['autocomplete_address'] ) ) {
					$attr .= 'data-autocomplete-address="' . ur_string_to_bool( $args['autocomplete_address'] ) . '"';
					$attr .= 'data-address-style="' . $args['address_style'] . '"';
					$attr .= 'data-current-location="' . ur_option_checked( 'user_registration_google_map_current_location', false ) . '"';
					if ( 'map' == $args['address_style'] ) {
						$field .= '<div id="ur-geolocation-map" class="ur-geolocation-map"></div>';
					}
				}

				$timpicker_class = '';
				if ( 'timepicker' === $args['type'] ) {
					$timpicker_class = 'ur-timepicker';
				}

				if ( empty( $extra_params ) ) {
					if ( $time_range ) {
						// Extract the start and end time if the time is given in range.
						$pattern = '/^(\d{1,2}:\d{2}(?:\s?[APap][Mm])?)\s+to\s+(\d{1,2}:\d{2}(?:\s?[APap][Mm])?)$/';

						$start_time = '';
						$end_time   = '';

						if ( preg_match( $pattern, $value, $times ) ) {
							$start_time = $times[1];
							$end_time   = $times[2];
						}
						$field .= '<div class = "ur-timepicker-range">';
						$field .= '<input data-range-type="start" data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '-start" type="' . esc_attr( $args['type'] ) . '" class="input-text timepicker-start ' . esc_attr( $timpicker_class ) . ' ' . $class . ' input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '-start" id="' . esc_attr( $args['id'] ) . '" placeholder="Start Time "  value="' . esc_attr( $start_time ? $start_time : $value ) . '" ' . implode( ' ', $custom_attributes ) . ' ' . $attr . '/>';
						$field .= '<input data-range-type="end" data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '-end" class="input-text timepicker-end ' . esc_attr( $timpicker_class ) . ' ' . $class . ' input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '-end" id="' . esc_attr( $args['id'] ) . '-end" placeholder="End Time"  value="' . esc_attr( $end_time ? $end_time : $value ) . '" ' . implode( ' ', $custom_attributes ) . ' ' . $attr . '/>';
						$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="hidden" class="input-text timepicker-time ' . esc_attr( $timpicker_class ) . ' ' . $class . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" />';
						$field .= '</div>';
					} else {

						$disabled = ( ( ( isset( $_REQUEST['page'] ) && isset( $args['field_key'] ) && 'user-registration-members' == $_REQUEST['page'] ) && 'user_email' === $args['field_key'] ) || ( isset( $args['repeater_field'] ) && $args['repeater_field'] ) ) ? ' readonly="readonly"' : '';

						$field .= '<input ' . $disabled . ' data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="' . esc_attr( $args['type'] ) . '" class="input-text ' . esc_attr( $timpicker_class ) . ' ' . $class . ' input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" ' . implode( ' ', $custom_attributes ) . ' ' . $attr . '/>';
					}
				} elseif ( ! empty( $extra_params ) ) {
					if ( $time_range ) {
						$field .= '<input data-range-type="start" data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '-start-test" type="' . esc_attr( $args['type'] ) . '" class="input-text ' . esc_attr( $timpicker_class ) . ' ' . $class . ' input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '-start" id="' . esc_attr( $args['id'] ) . '-start" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" ' . implode( ' ', $custom_attributes ) . ' ' . $attr . '/>';
						$field .= '<input data-range-type="end" data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '-end" class="input-text timepicker-end ' . esc_attr( $timpicker_class ) . ' ' . $class . ' input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '-end" id="' . esc_attr( $args['id'] ) . '-end" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" ' . implode( ' ', $custom_attributes ) . ' ' . $attr . '/>';
						$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="hidden" class="input-text timepicker-time ' . $class . ' input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" />';
					} else {
						$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="' . esc_attr( $args['type'] ) . '" class="input-text ' . esc_attr( $timpicker_class ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" ' . implode( ' ', $custom_attributes ) . ' ' . $attr . ' />';
					}
				}

				if ( isset( $args['field_key'] ) && 'user_email' === $args['field_key'] ) {

					$user_id       = ! empty( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : get_current_user_id();
					$pending_email = get_user_meta( $user_id, 'user_registration_pending_email', true );
					$expiration    = get_user_meta( $user_id, 'user_registration_pending_email_expiration', true );
					$cancel_url    = esc_url(
						add_query_arg(
							array(
								'cancel_email_change' => $user_id,
								'_wpnonce'            => wp_create_nonce( 'cancel_email_change_nonce' ),
							),
							ur_get_my_account_url() . get_option( 'user_registration_myaccount_edit_profile_endpoint', 'edit-profile' )
						)
					);

					if ( ! empty( $pending_email ) && time() <= $expiration ) {

						if ( ! empty( $_REQUEST['action'] ) && 'edit' === $_REQUEST['action'] ) {

							$field .= sprintf(
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
					} else {
						// Remove the confirmation key, pending email and expiry date.
						UR_Form_Handler::delete_pending_email_change( $user_id );
					}
				}

				if ( ! is_admin() ) {
					/**
					 * Filters the icon markup for a user registration form field.
					 *
					 * The 'user_registration_field_icon' filter allows developers to modify
					 * the icon markup associated with a specific form field during the user
					 * registration process. It provides an opportunity to customize the icon
					 * based on the original icon markup, form ID, and field arguments.
					 *
					 * @param string $field The original icon markup associated with the form field.
					 * @param int $form_id The ID of the user registration form.
					 * @param array $args The arguments for the form field.
					 */
					$field  = apply_filters( 'user_registration_field_icon', $field, $form_id, $args );
					$field .= ' </span> ';
				}
				break;

			case 'date':
				$extra_params_key  = str_replace( 'user_registration_', 'ur_', $key ) . '_params';
				$extra_params      = json_decode( get_user_meta( get_current_user_id(), $extra_params_key, true ) );
				$date_slot_booking = isset( $args['enable_date_slot_booking'] ) ? $args['enable_date_slot_booking'] : '';
				if ( ur_string_to_bool( $date_slot_booking ) ) {

					$custom_attributes[] = 'data-enable-date-slot-booking="' . $date_slot_booking . '"';
					$class              .= ' date-slot-booking';
				}

				if ( ! isset( $args['icon'] ) && empty( $args['icon'] ) ) {
					$class .= ' without_icon';
				}

				$actual_value = $value;
				if ( isset( $args['custom_attributes']['data-date-format'] ) ) {
					$date_format  = $args['custom_attributes']['data-date-format'];
					$default_date = isset( $args['custom_attributes']['data-default-date'] ) ? $args['custom_attributes']['data-default-date'] : '';
					if ( empty( $value ) && ur_string_to_bool( $default_date ) ) {
						$value        = date_i18n( $date_format );
						$actual_value = date_i18n( $date_format );
					}
				}

				$field .= ' <span class="input-wrapper"> ';

				if ( empty( $extra_params ) ) {
					$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '" type="text" value="' . esc_attr( $actual_value ) . '" class="ur-flatpickr-field regular-text ' . esc_attr( $class ) . '" readonly placeholder="' . esc_attr( $args['placeholder'] ) . '" ' . implode( ' ', $custom_attributes ) . ' />';
					$field .= '<input type="hidden" id="formated_date" value="' . esc_attr( $value ) . '"/>';
					$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '" type="text" data-field-type="' . esc_attr( $args['type'] ) . '" value="' . esc_attr( $actual_value ) . '" class="input-text input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  ' . implode( ' ', $custom_attributes ) . ' style="display:none"/>';
				} else {
					$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '" type="text" value="' . esc_attr( $actual_value ) . '"  class="ur-flatpickr-field regular-text ' . $class . '" readonly placeholder="' . esc_attr( $args['placeholder'] ) . '" ' . implode( ' ', $custom_attributes ) . ' />';
					$field .= '<input type="hidden" id="formated_date" value="' . esc_attr( $value ) . '"/>';
					$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '" type="text" data-field-type="' . esc_attr( $args['type'] ) . '" value="' . esc_attr( $actual_value ) . '" class="input-text ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  ' . implode( ' ', $custom_attributes ) . ' style="display:none" />';
				}

				if ( ! is_admin() ) {
					/**
					 * Filters the icon markup for a user registration form field.
					 *
					 * The 'user_registration_field_icon' filter allows developers to modify
					 * the icon markup associated with a specific form field during the user
					 * registration process. It provides an opportunity to customize the icon
					 * based on the original icon markup, form ID, and field arguments.
					 *
					 * @param string $field The original icon markup associated with the form field.
					 * @param int $form_id The ID of the user registration form.
					 * @param array $args The arguments for the form field.
					 */
					$field = apply_filters( 'user_registration_field_icon', $field, $form_id, $args );
				}
				$field .= '</span> ';
				break;

			case 'color':
				$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="text" class="input-text input-color ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" data-default-color="' . esc_attr( $args['default'] ) . '" ' . implode( ' ', $custom_attributes ) . ' />';
				break;

			case 'select':
				$default_value = isset( $args['default_value'] ) ? $args['default_value'] : ''; // Backward compatibility. Modified since 1.5.7.

				$value           = ! empty( $value ) ? $value : $default_value;
				$options         = $field .= '';
				$backtrace       = debug_backtrace();
				$parent_function = isset( $backtrace[1] ) ? $backtrace[1]['function'] : '';
				$args['options'] = ( 'frontend_includes' === $parent_function ) ? apply_filters( 'override_options_for_select_field', $args['options'], $args['id'] ) : $args['options'];

				if ( ! empty( $args['options'] ) ) {
					// If we have a blank option, select2 needs a placeholder.
					if ( '' === $value && ! empty( $args['placeholder'] ) ) {
						$options .= '<option value="" selected disabled>' . esc_html( $args['placeholder'] ) . '</option>';
					}

					if ( isset( $args['field_key'] ) && 'country' === $args['field_key'] && empty( $args['placeholder'] ) && empty( $value ) ) {
						$options .= '<option value="" selected >' . esc_html__( 'Select a country', 'user-registration' ) . '</option>';
					}

					$custom_attributes[] = 'data-allow_clear="true"';
					$is_json             = is_string( $value ) && preg_match( '/^\{.*\}$/s', $value ) ? true : false;
					if ( $is_json ) {
						$value = json_decode( $value, true );
					}
					$country = is_array( $value ) ? ( ! empty( $value['country'] ) ? $value['country'] : '' ) : $value;

					foreach ( $args['options'] as $option_key => $option_text ) {
						$selected_attribute = '';

						if ( '' !== $value ) {
							$selected_attribute = selected( $country, trim( $option_key ), false );
						}
						$options .= '<option value="' . esc_attr( trim( $option_key ) ) . '" ' . $selected_attribute . '>' . esc_attr( trim( $option_text ) ) . '</option>';
					}
					$state_enabled    = ! empty( $args['enable_state'] ) && '1' == $args['enable_state'];
					$is_country_field = isset( $args['field_key'] ) && 'country' === $args['field_key'];
					$select_class     = $is_country_field ? 'ur-field-address-country select ' : 'select ';
					$field           .= '<select data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" class="' . $select_class . esc_attr( implode( ' ', $args['input_class'] ) ) . '" ' . implode( ' ', $custom_attributes ) . ' data-placeholder="' . esc_attr( $args['placeholder'] ) . '" data-state-enabled="' . $state_enabled . '">
					' . $options . '
					</select>';

					$states_json = ur_file_get_contents( '/assets/extensions-json/states.json' );
					$state_list  = json_decode( $states_json, true );
					$state_list  = is_array( $state_list ) ? $state_list : array();

					$states = isset( $state_list[ $country ] ) ? $state_list[ $country ] : '';

					if ( $state_enabled ) {
						$is_state_disabled = isset( $args['custom_attributes']['disabled'] ) && 'disabled' === $args['custom_attributes']['disabled'] ? true : false;
						$is_state_readonly = isset( $args['custom_attributes']['readonly'] ) && 'readonly' === $args['custom_attributes']['readonly'] ? true : false;

						$field .= '<label for="' . $args['id'] . '" class="ur-label ur-state-label">' . __( 'State', 'user-registration' ) . '</label>';
						$field .= '<span class="input-wrapper ur-field-address-state-outer-wrapper">';
						if ( isset( $value['state'], $states[ $value['state'] ] ) ) {
							$field .= '<select id="' . esc_attr( $args['id'] ) . '_state" class="ur-field-address-state select ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . $args['id'] . '_state" ' . ( $is_state_disabled ? 'disabled' : '' ) . ' ' . ( $is_state_readonly ? 'readonly' : '' ) . '>';
							foreach ( $states as $state_key => $state_name ) {
								$state_selected_attribute = '';
								if ( '' !== $value['state'] ) {
									$state_selected_attribute = selected( $value['state'], trim( $state_key ), false );
								}
								$field .= '<option value="' . esc_attr( trim( $state_key ) ) . '" ' . $state_selected_attribute . '>' . esc_attr( trim( $state_name ) ) . '</option>';
							}
							$field .= '</select>';
						} else {
							$field .= '<input type="text" class="ur-field-address-state input-text ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . $args['id'] . '_state" value="' . esc_attr( ! empty( $value['state'] ) ? $value['state'] : '' ) . '" />';
						}
						$field .= '</span>';
					}
				}

				break;

			case 'multiselect':
				$options = $field .= '';

				if ( is_serialized( $value ) ) {
					$default_value = unserialize( $value, array( 'allowed_classes' => false ) ); //phpcs:ignore;
				} else {
					$default_value = $value;
				}

				$args['options'] = apply_filters( 'override_options_for_select_field', $args['options'] );

				if ( ! empty( $args['options'] ) ) {
					foreach ( $args['options'] as $option_key => $option_text ) {

						if ( '' === $option_key ) {
							// If we have a blank option, select2 needs a placeholder.
							if ( empty( $args['placeholder'] ) ) {
								$args['placeholder'] = $option_text ? $option_text : __( 'Choose an option', 'user-registration' );
							}
							$custom_attributes[] = 'data-allow_clear="true"';
						}

						if ( is_array( $default_value ) ) {
							$options .= '<option value="' . esc_attr( trim( $option_key ) ) . '" ' . selected( in_array( trim( $option_key ), $default_value ), true, false ) . '>' . esc_attr( trim( $option_text ) ) . '</option>';
						} else {
							$options .= '<option value="' . esc_attr( trim( $option_key ) ) . '" ' . selected( $default_value, trim( $option_key ), false ) . '>' . esc_attr( trim( $option_text ) ) . '</option>';
						}
					}

					$field .= '<select multiple data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '[]" id="' . esc_attr( $args['id'] ) . '" class="select ur-enhanced-select' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" ' . implode( ' ', $custom_attributes ) . ' data-placeholder="' . esc_attr( $args['placeholder'] ) . '">
							' . $options . '
						</select>';
				}
				break;

			case 'radio':
				$default_value = isset( $args['default_value'] ) ? $args['default_value'] : ''; // Backward compatibility. Modified since 1.5.7.
				$value         = ! empty( $value ) ? $value : $default_value;
				$label_id      = current( array_keys( $args['options'] ) );

				if ( empty( $args['options'] ) ) {
					return;
				}

				if ( isset( $args['image_choice'] ) && ur_string_to_bool( $args['image_choice'] ) ) {
					$field .= '<ul class="user-registration-image-options">';
					foreach ( $args['image_options'] as $option_index => $option_text ) {
						$option_label = is_array( $option_text ) ? $option_text['label'] : $option_text->label;
						$option_image = is_array( $option_text ) ? $option_text['image'] : $option_text->image;

						$field  .= '<li class="ur-radio-list">';
						$checked = '';
						if ( ! empty( $value ) ) {
							$checked = checked( $value, trim( $option_index ), false );
						}

						$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="radio" class="input-radio ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" value="' . esc_attr( trim( $option_index ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_label ) . '" ' . implode( ' ', $custom_attributes ) . ' / ' . $checked . ' /> ';
						$field .= '<label for="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_label ) . '" class="radio">';

						if ( ! empty( $option_image ) ) {
							$field .= '<span class="user-registration-image-choice">';
							$field .= '<img src="' . esc_url( $option_image ) . '" alt="' . esc_attr( trim( $option_label ) ) . '" width="200px">';
							$field .= '</span>';
						}

						$field .= wp_kses(
							trim( $option_label ),
							array(
								'a'    => array(
									'href'  => array(),
									'title' => array(),
								),
								'span' => array(),
							)
						) . '</label></li>';
					}
					$field .= '</ul>';
				} else {
					$field .= '<ul>';
					foreach ( $args['options'] as $option_index => $option_text ) {

						$field .= '<li class="ur-radio-list">';

						$checked = '';
						if ( ! empty( $value ) ) {
							$checked = checked( $value, trim( $option_index ), false );
						}

						$field .= sprintf(
							'<input data-rules="%s" data-id="%s" type="radio" class="input-radio %s" value="%s" name="%s" id="%s" %s %s /> ',
							esc_attr( $rules ),
							esc_attr( $key ),
							esc_attr( implode( ' ', $args['input_class'] ) ),
							esc_attr( trim( $option_index ) ),
							esc_attr( $key . ( '' !== $current_row ? "_{$current_row}" : '' ) ),
							esc_attr( "{$args['id']}_{$option_text}" ),
							implode( ' ', $custom_attributes ),
							$checked
						);

						$field .= '<label for="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_text ) . '" class="radio">';

						$field .= wp_kses(
							trim( $option_text ),
							array(
								'a'    => array(
									'href'  => array(),
									'title' => array(),
								),
								'span' => array(),
							)
						) . '</label></li>';
					}
					$field .= '</ul>';
				}
				break;

			case 'html':
				$content = isset( $args['html_content'] ) ? $args['html_content'] : '';

				$field .= $content;
				break;

			case 'hidden':
				$hidden_value = $args['hidden_value'] ?? $args['default'];
				$custom_class = $args['custom_class'] ?? '';
				$input_type   = ! $is_edit ? 'type="hidden"' : 'type="text"';
				if ( $is_edit ) {
					$default_value = $args['default'] ?? '';
					$hidden_value  = ! empty( $value ) ? $value : $default_value;
					$label         = $args['label'] ?? 'Hidden Field';
					$field        .= '<label for="' . esc_attr( $key ) . '" class="ur-label">' . esc_html( $label ) . '</label>';
					$field        .= '<span class="input-wrapper">';
				}
				$field .= '<input ' . $input_type . ' data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="input-hidden input-text ur-frontend-field ur-edit-profile-field' . esc_attr( $custom_class ) . '" id="' . esc_attr( $args['id'] ) . '"value="' . esc_attr( $hidden_value ) . '" data-field-type="hidden"/>';
				$field .= ( $is_edit ) ? '</span>' : '';
				break;
			case 'tinymce':
				$default_value = isset( $args['default_value'] ) ? $args['default_value'] : ''; // Backward compatibility. Modified since 1.5.7.

				$editor_settings = array(
					'name'          => esc_attr( $args['id'] ),
					'id'            => esc_attr( $args['id'] ),
					'style'         => esc_attr( $args['css'] ),
					'default'       => esc_attr( $args['default'] ),
					'class'         => esc_attr( $args['class'] ),
					'quicktags'     => array( 'buttons' => 'em,strong,link' ),
					'media_buttons' => false,
					'tinymce'       => array(
						'statusbar'               => false,
						'toolbar1'                => 'undo,redo,formatselect,fontselect,fontsizeselect,bold,italic,forecolor,alignleft,aligncenter,alignright,alignjustify,bullist,numlist,outdent,indent,removeformat',
						'toolbar2'                => '',
						'toolbar3'                => '',
						'toolbar4'                => '',
						'theme_advanced_buttons2' => '',
						'plugins'                 => 'wordpress,wpautoresize,wplink,wpdialogs,wptextpattern,wpview,colorpicker,textcolor,hr,charmap,link,fullscreen,lists',
					),
					'editor_css'    => '<style>#wp-excerpt-editor-container .wp-editor-area{height:175px; width:100%;}</style>',
				);

				$value = ! empty( $value ) ? $value : $default_value;

				$field .= '<div class="user-registration-tinymce-field ' . $args['id'] . '">';

				// Output buffer for tinymce editor.
				ob_start();
				wp_editor( $value, $args['id'], $editor_settings );
				$field .= ob_get_clean();

				$field .= '</div>';

				break;
		}

		// End switch().
		if ( $args['description'] ) {
			$field .= '<span class="description">' . $args['description'] . '</span>';
		}

		if ( isset( $args['notice'] ) && ! empty( $args['notice'] ) ) {
			$notice_type    = isset( $args['notice']['type'] ) ? $args['notice']['type'] : 'info';
			$notice_message = isset( $args['notice']['message'] ) ? $args['notice']['message'] : '';
			$field         .= '<span class="ur-settings-notice ur-settings-notice--' . $notice_type . '">' . $notice_message . '</span>';
		}

		if ( ! empty( $field ) ) {

			$field_html = '';
			if ( $args['label'] && 'checkbox' != $args['type'] && 'toggle' != $args['type'] && 'hidden' !== $args['type'] ) {
				$field_html .= '<label for="' . esc_attr( $label_id ) . '" class="ur-label">' . wp_kses(
					$args['label'],
					array(
						'a'    => array(
							'href'  => array(),
							'title' => array(),
						),
						'span' => array(),
					)
				) . $required . $tooltip_html . '</label>';
			}

			$field_html     .= $field;
			$container_class = esc_attr( implode( ' ', $args['class'] ) );
			$container_id    = esc_attr( $args['id'] ) . '_field';
			$field           = sprintf( $field_container, $container_class, $container_id, $field_html );
		}

		/**
		 * Filters the form field based on its type.
		 *
		 * The dynamic 'user_registration_form_field_{type}' filter allows developers to modify
		 * the form field for a specific type during the user registration process. The {type}
		 * placeholder is replaced with the actual field type, providing a flexible way to customize
		 * the form field based on its type, field key, arguments, and value.
		 *
		 * @param string $field The original form field markup for the specific type.
		 * @param string $key The key identifying the form field.
		 * @param array $args The arguments for the form field.
		 * @param mixed $value The value of the form field.
		 */
		$field = apply_filters( 'user_registration_form_field_' . $args['type'], $field, $key, $args, $value, $current_row );

		if ( $args['return'] ) {
			return $field;
		} else {
			echo $field; //PHPCS:ignore;
		}
	}
} // End if().

if ( ! function_exists( 'user_registration_form_data' ) ) {

	/**
	 * Get form fields to display on profile tab
	 *
	 * @param string $user_id User Id.
	 * @param string $form_id Form Id.
	 *
	 * @return array
	 */
	function user_registration_form_data( $user_id = 0, $form_id = 0 ) {

		$all_meta_value     = get_user_meta( $user_id );
		$allowed_user_roles = array( 'administrator' );
		$current_user       = wp_get_current_user();
		$is_admin           = count( array_intersect( $allowed_user_roles, (array) $current_user->roles ) ) > 0;

		$fields             = array();
		$post_content_array = ( $form_id ) ? UR()->form->get_form( $form_id, array( 'content_only' => true ) ) : array();

		$all_meta_value_keys = array();
		if ( gettype( $all_meta_value ) === 'array' ) {
			$all_meta_value_keys = array_keys( $all_meta_value );
		}
		/**
		 * Filters all fields in the user registration profile account during rendering.
		 *
		 * The 'user_registration_profile_account_filter_all_fields' filter allows developers
		 * to modify all fields in the user registration profile account when rendering. It provides
		 * an opportunity to customize the post content array and form ID associated with the
		 * profile account.
		 *
		 * @param array $post_content_array The original post content array for the profile account.
		 * @param int $form_id The ID of the user registration form associated with the account.
		 * @param bool $is_admin Is the current logged in user admin
		 */
		$post_content_array = apply_filters( 'user_registration_profile_account_filter_all_fields', $post_content_array, $form_id, $is_admin );

		foreach ( $post_content_array as $post_content_row ) {
			foreach ( $post_content_row as $post_content_grid ) {
				foreach ( $post_content_grid as $field ) {

					$field_name             = isset( $field->general_setting->field_name ) ? $field->general_setting->field_name : '';
					$field_label            = isset( $field->general_setting->label ) ? $field->general_setting->label : '';
					$field_description      = isset( $field->general_setting->description ) ? $field->general_setting->description : '';
					$placeholder            = isset( $field->general_setting->placeholder ) ? $field->general_setting->placeholder : '';
					$options                = isset( $field->general_setting->options ) ? $field->general_setting->options : array();
					$field_key              = isset( $field->field_key ) ? ( $field->field_key ) : '';
					$field_type             = isset( $field->field_key ) ? ur_get_field_type( $field_key ) : '';
					$required               = isset( $field->general_setting->required ) ? $field->general_setting->required : '';
					$required               = ur_string_to_bool( $required );
					$enable_cl              = isset( $field->advance_setting->enable_conditional_logic ) && ur_string_to_bool( $field->advance_setting->enable_conditional_logic );
					$cl_map                 = isset( $field->advance_setting->cl_map ) ? $field->advance_setting->cl_map : '';
					$custom_attributes      = isset( $field->general_setting->custom_attributes ) ? $field->general_setting->custom_attributes : array();
					$enable_validate_unique = isset( $field->advance_setting->validate_unique ) ? $field->advance_setting->validate_unique : false;
					$validate_message       = isset( $field->advance_setting->validation_message ) ? $field->advance_setting->validation_message : esc_html__( 'This field value needs to be unique.', 'user-registration' );
					$enable_payment_slider  = isset( $field->advance_setting->enable_payment_slider ) ? $field->advance_setting->enable_payment_slider : false;
					$enable_image_choice    = isset( $field->general_setting->image_choice ) ? $field->general_setting->image_choice : false;
					$enable_image_choice    = isset( $field->general_setting->image_choice ) ? $field->general_setting->image_choice : false;
					$default                = '';

					if ( isset( $field->general_setting->default_value ) ) {
						$default = $field->general_setting->default_value;
					} elseif ( isset( $field->advance_setting->default_value ) ) {
						$default = $field->advance_setting->default_value;
					}
					if ( empty( $field_label ) ) {
						$field_label_array = explode( '_', $field_name );
						$field_label       = join( ' ', array_map( 'ucwords', $field_label_array ) );
					}

					if ( ! empty( $field_name ) ) {

						$extra_params = array();

						switch ( $field_key ) {

							case 'radio':
							case 'select':
								$advanced_options        = isset( $field->advance_setting->options ) ? $field->advance_setting->options : '';
								$advanced_options        = explode( ',', $advanced_options );
								$extra_params['options'] = ! empty( $options ) ? $options : $advanced_options;
								$extra_params['options'] = array_map( 'trim', $extra_params['options'] );

								$extra_params['options']      = array_combine( $extra_params['options'], $extra_params['options'] );
								$extra_params['image_choice'] = $enable_image_choice;

								break;

							case 'checkbox':
								$advanced_options        = isset( $field->advance_setting->choices ) ? $field->advance_setting->choices : '';
								$advanced_options        = explode( ',', $advanced_options );
								$extra_params['options'] = ! empty( $options ) ? $options : $advanced_options;
								$extra_params['options'] = array_map( 'trim', $extra_params['options'] );

								$extra_params['options']      = array_combine( $extra_params['options'], $extra_params['options'] );
								$extra_params['image_choice'] = $enable_image_choice;

								break;

							case 'date':
								$date_format       = isset( $field->advance_setting->date_format ) ? $field->advance_setting->date_format : '';
								$min_date          = isset( $field->advance_setting->min_date ) ? str_replace( '/', '-', $field->advance_setting->min_date ) : '';
								$max_date          = isset( $field->advance_setting->max_date ) ? str_replace( '/', '-', $field->advance_setting->max_date ) : '';
								$set_current_date  = isset( $field->advance_setting->set_current_date ) ? ur_string_to_bool( $field->advance_setting->set_current_date ) : '';
								$enable_date_range = isset( $field->advance_setting->enable_date_range ) ? ur_string_to_bool( $field->advance_setting->enable_date_range ) : '';
								$date_localization = isset( $field->advance_setting->date_localization ) ? $field->advance_setting->date_localization : '';
								$extra_params['custom_attributes']['data-date-format'] = $date_format;

								if ( isset( $field->advance_setting->enable_min_max ) && ur_string_to_bool( $field->advance_setting->enable_min_max ) ) {
									$extra_params['custom_attributes']['data-min-date'] = '' !== $min_date ? date_i18n( $date_format, strtotime( $min_date ) ) : '';
									$extra_params['custom_attributes']['data-max-date'] = '' !== $max_date ? date_i18n( $date_format, strtotime( $max_date ) ) : '';
								}
								$extra_params['custom_attributes']['data-default-date'] = $set_current_date;
								$extra_params['custom_attributes']['data-mode']         = $enable_date_range;
								$extra_params['custom_attributes']['data-locale']       = $date_localization;
								break;

							case 'country':
								$class_name              = ur_load_form_field_class( $field_key );
								$extra_params['options'] = $class_name::get_instance()->get_selected_countries( $form_id, $field_name );
								break;

							case 'file':
								$extra_params['max_files'] = isset( $field->general_setting->max_files ) ? $field->general_setting->max_files : '';
								break;

							case 'phone':
								$extra_params['phone_format'] = isset( $field->general_setting->phone_format ) ? $field->general_setting->phone_format : '';
								break;

							default:
								break;
						}

						$extra_params['default'] = isset( $all_meta_value[ 'user_registration_' . $field_name ][0] ) ? $all_meta_value[ 'user_registration_' . $field_name ][0] : ( isset( $all_meta_value[ $field_name ][0] ) ? $all_meta_value[ $field_name ][0] : '' );

						if ( empty( $extra_params['default'] ) ) {
							$extra_params['default'] = $default;
						}

						if ( empty( $extra_params['default'] ) ) {
							$user_details = get_user_by( 'ID', $user_id );
							$user_info    = (array) $user_details->data;

							$extra_params['default'] = isset( $user_info[ $field_name ] ) ? $user_info[ $field_name ] : '';
						}
						$user_profile_fields = ur_get_user_profile_field_only();

						$is_admin_request = $_REQUEST['is_admin_user'] ?? false; // phpcs:ignore
						if ( $is_admin_request || ( isset( $_REQUEST['action'] ) && sanitize_text_field( 'edit' === $_REQUEST['action'] || 'view' === $_REQUEST['action'] ) && $user_id !== get_current_user_id() ) ) { // phpcs:ignore
							array_push( $user_profile_fields, 'user_pass' );
						}

						if ( in_array( $field_key, $user_profile_fields ) ) { // phpcs:ignore

							$fields[ 'user_registration_' . $field_name ] = array(
								'label'       => ur_string_translation( $form_id, 'user_registration_' . $field_name . '_label', $field_label ),
								'description' => ur_string_translation( $form_id, 'user_registration_' . $field_name . '_description', $field_description ),
								'type'        => $field_type,
								'placeholder' => ur_string_translation( $form_id, 'user_registration_' . $field_name . '_placeholder', $placeholder ),
								'field_key'   => $field_key,
								'required'    => $required,
							);

							if ( true === $enable_cl ) {
								$fields[ 'user_registration_' . $field_name ]['enable_conditional_logic'] = $enable_cl;
								$fields[ 'user_registration_' . $field_name ]['cl_map']                   = $cl_map;
							}
						}

						if ( true === $enable_cl ) {
							$fields[ 'user_registration_' . $field_name ]['enable_conditional_logic'] = $enable_cl;
							$fields[ 'user_registration_' . $field_name ]['cl_map']                   = $cl_map;
						}

						if ( count( $custom_attributes ) > 0 ) {
							$extra_params['custom_attributes'] = $custom_attributes;
						}

						if ( isset( $field->advance_setting->validate_unique ) ) {
							$fields[ 'user_registration_' . $field_name ]['validate_unique']  = $enable_validate_unique;
							$fields[ 'user_registration_' . $field_name ]['validate_message'] = ur_string_translation( $form_id, 'ur_validation_message_for_duplicate', $validate_message );
						}

						if ( isset( $field->advance_setting->enable_payment_slider ) ) {
							$fields[ 'user_registration_' . $field_name ]['enable_payment_slider'] = $enable_payment_slider;
						}

						if ( isset( $fields[ 'user_registration_' . $field_name ] ) && count( $extra_params ) > 0 ) {
							$fields[ 'user_registration_' . $field_name ] = array_merge( $fields[ 'user_registration_' . $field_name ], $extra_params );
						}

						$filter_data = array(
							'fields'     => $fields,
							'field'      => $field,
							'field_name' => $field_name,
						);
						/**
						 * Filters a specific field in the user registration profile account during rendering.
						 *
						 * The dynamic 'user_registration_profile_account_filter_{field_key}' filter allows developers
						 * to modify a specific field in the user registration profile account when rendering. The {field_key}
						 * placeholder is replaced with the actual field key, providing a flexible way to customize the
						 * field's filter data and the form ID associated with the account.
						 *
						 * @param array $filter_data The original filter data for the specific field.
						 * @param int $form_id The ID of the user registration form associated with the account.
						 */
						$filtered_data_array = apply_filters( 'user_registration_profile_account_filter_' . $field_key, $filter_data, $form_id );
						if ( isset( $filtered_data_array['fields'] ) ) {
							$fields = $filtered_data_array['fields'];

						}
					} // End if().
				} // End foreach().
			} // End foreach().
		} // End foreach().

		/**
		 * Filter to add extra user data to profile details if any.
		 */
		$fields = apply_filters( 'user_registration_user_extra_profile_details', $fields, $form_id, $all_meta_value );

		return $fields;
	}
} // End if().

if ( ! function_exists( 'user_registration_account_content' ) ) {

	/**
	 * My Account content output.
	 */
	function user_registration_account_content() {
		global $wp;

		if ( ! empty( $wp->query_vars ) ) {
			foreach ( $wp->query_vars as $key => $value ) {
				// Ignore pagename param.
				if ( 'pagename' === $key ) {
					continue;
				}

				if ( has_action( 'user_registration_account_' . $key . '_endpoint' ) ) {
					/**
					 * Action to add the my account content.
					 *
					 * Dynamic portion of hook, $key
					 *
					 * @param array $value The key values.
					 */
					do_action( 'user_registration_account_' . $key . '_endpoint', $value );

					return;
				}
			}
		}

		if ( ur_string_to_bool( get_option( 'urm_is_new_installation', false ) ) ) {
			$user_id         = get_current_user_id();
			$form_id         = ur_get_form_id_by_userid( $user_id );
			$user_data       = get_userdata( $user_id );
			$user_data       = $user_data->data;
			$form_data_array = ( $form_id ) ? UR()->form->get_form( $form_id, array( 'content_only' => true ) ) : array();
			if ( ! empty( $form_data_array ) ) {
				// No endpoint found? Default to dashboard.
				ur_get_template(
					'myaccount/form-edit-profile.php',
					array(
						'current_user'   => get_user_by( 'id', get_current_user_id() ),
						'endpoint_label' => ur_get_account_menu_items()['edit-profile'] ?? '',
					)
				);
			} else {
				ur_get_template(
					'myaccount/form-edit-profile-non-urm-user.php',
					array(
						// 'endpoint_label' => ur_get_account_menu_items()['edit-profile'],
					)
				);
			}
		} else {
			// No endpoint found? Default to dashboard.
			ur_get_template(
				'myaccount/dashboard.php',
				array(
					'current_user'   => get_user_by( 'id', get_current_user_id() ),
					'endpoint_label' => ur_get_account_menu_items()['dashboard'],
				)
			);
		}
	}
}

if ( ! function_exists( 'user_registration_account_navigation' ) ) {

	/**
	 * My Account navigation template.
	 */
	function user_registration_account_navigation() {
		ur_get_template( 'myaccount/navigation.php' );
	}
}

if ( ! function_exists( 'user_registration_account_dashboard' ) ) {

	/**
	 * My Account > Dashboard template.
	 */
	function user_registration_account_dashboard() {
		ur_get_template(
			'myaccount/dashboard.php',
			array(
				'current_user'   => get_user_by( 'id', get_current_user_id() ),
				'endpoint_label' => ur_get_account_menu_items()['dashboard'],
			)
		);
	}
}

if ( ! function_exists( 'user_registration_account_edit_profile' ) ) {

	/**
	 * My Account > Edit profile template.
	 */
	function user_registration_account_edit_profile() {
		UR_Shortcode_My_Account::edit_profile();
	}
}

if ( ! function_exists( 'user_registration_account_edit_account' ) ) {

	/**
	 * My Account > Edit account template.
	 */
	function user_registration_account_edit_account() {
		UR_Shortcode_My_Account::edit_account();
	}
}

/**
 * Get logout endpoint.
 *
 * @param string $redirect URL.
 *
 * @return string
 */
function ur_logout_url( $redirect = '' ) {
	$logout_endpoint = get_option( 'user_registration_logout_endpoint', 'user-logout' );

	global $post;
	$wp_version   = '5.0';
	$post_content = isset( $post->post_content ) ? $post->post_content : '';

	if ( ( ur_post_content_has_shortcode( 'user_registration_login' ) || ur_post_content_has_shortcode( 'user_registration_my_account' ) ) && is_user_logged_in() ) {
		if ( version_compare( $GLOBALS['wp_version'], $wp_version, '>=' ) ) {
			$blocks        = parse_blocks( $post_content );
			$new_shortcode = '';

			foreach ( $blocks as $block ) {
				if ( ( 'core/shortcode' === $block['blockName'] || 'core/paragraph' === $block['blockName'] ) && isset( $block['innerHTML'] ) ) {
					$new_shortcode = ( 'core/shortcode' === $block['blockName'] ) ? $block['innerHTML'] : wp_strip_all_tags( $block['innerHTML'] );
				} elseif ( 'user-registration/form-selector' === $block['blockName'] && isset( $block['attrs']['shortcode'] ) ) {
					$new_shortcode = '[' . $block['attrs']['shortcode'] . ']';
				}

				if ( 'user-registration/form-selector' === $block['blockName'] && isset( $block['attrs']['logoutUrl'] ) ) {
					$redirect = $block['attrs']['logoutUrl'];
				}
			}
			preg_match( '/' . get_shortcode_regex() . '/s', $new_shortcode, $matches );

		} else {
			preg_match( '/' . get_shortcode_regex() . '/s', $post_content, $matches );
		}

		$matches_attr = isset( $matches[3] ) ? $matches[3] : '';
		$attributes   = shortcode_parse_atts( $matches_attr );
		/**
		 * Introduced logout_redirect parameter in user_registration_my_account shortcode.
		 *
		 * @since  1.7.5
		 */
		if ( isset( $attributes['logout_redirect'] ) ) {
			$redirect = isset( $attributes['logout_redirect'] ) ? $attributes['logout_redirect'] : '';
			$redirect = trim( $redirect, ']' );
			$redirect = trim( $redirect, '"' );
			$redirect = trim( $redirect, "'" );
			$redirect = '' != $redirect ? ur_check_external_url( $redirect ) : ur_get_page_permalink( 'myaccount' );
		}
	} else {
		$blocks = parse_blocks( $post_content );

		foreach ( $blocks as $block ) {
			if ( ( 'user-registration/form-selector' === $block['blockName'] || 'user-registration/myaccount' === $block['blockName'] || 'user-registration/login-form' === $block['blockName'] ) && isset( $block['attrs']['logoutUrl'] ) ) {
				$redirect = '' != $block['attrs']['logoutUrl'] ? ur_check_external_url( $block['attrs']['logoutUrl'] ) : ur_get_page_permalink( 'myaccount' );
			} else {

				$new_shortcode = wp_strip_all_tags( $block['innerHTML'] );
				$pattern       = '/\[user_registration_my_account(?:\s+redirect_url="[^"]*")?(?:\s+logout_redirect="[^"]*")?\s*\]/';

				preg_match( $pattern, $new_shortcode, $shortcodes );

				if ( ! empty( $shortcodes[0] ) ) {
					preg_match( '/' . get_shortcode_regex() . '/s', $shortcodes[0], $matches );
					$matches_attr = isset( $matches[3] ) ? $matches[3] : '';
					$attributes   = shortcode_parse_atts( $matches_attr );

					/**
					 * Introduced logout_redirect parameter in user_registration_my_account shortcode.
					 *
					 * @since  1.7.5
					 */
					if ( isset( $attributes['logout_redirect'] ) ) {
						$redirect = isset( $attributes['logout_redirect'] ) ? $attributes['logout_redirect'] : '';
						$redirect = trim( $redirect, ']' );
						$redirect = trim( $redirect, '"' );
						$redirect = trim( $redirect, "'" );
						$redirect = '' != $redirect ? ur_check_external_url( $redirect ) : ur_get_page_permalink( 'myaccount' );
					}
				}
			}
		}
	}
	/**
	 * Filters the redirect URL after user logout.
	 *
	 * The 'user_registration_redirect_after_logout' filter allows developers to modify
	 * the redirect URL after a user logs out. It provides an opportunity to customize
	 * the redirection based on the original redirect URL.
	 *
	 * @param string $redirect The original redirect URL after user logout.
	 */
	$redirect = apply_filters( 'user_registration_redirect_after_logout', $redirect );

	if ( $logout_endpoint && ! is_front_page() ) {
		if ( home_url( '/' ) === $redirect ) {
			return wp_logout_url( $redirect );
		} else {
			return wp_nonce_url( ur_get_endpoint_url( 'user-logout', '', $redirect ), 'user-logout' );
		}
	} else {
		if ( '' === $redirect ) {
			$redirect = ur_get_page_permalink( 'myaccount' );
		}

		return wp_logout_url( $redirect );
	}
}

/**
 * See if current page elementor page for editing.
 *
 * @return bool
 * @since 1.8.5
 */
function is_elementor_editing_page() {
	return ( ! empty( $_POST['action'] ) && 'elementor_ajax' === $_POST['action'] ) || //PHPCS:ignore;
	       ! empty( $_GET['elementor-preview'] ) || //PHPCS:ignore;
	       ( ! empty( $_GET['action'] ) && 'elementor' === $_GET['action'] ); //PHPCS:ignore;
}

/**
 * Check if the URL is slug or external url.
 *
 * @param string $url URL.
 *
 * @return string
 */
function ur_check_external_url( $url ) {
	$all_page_slug = ur_get_all_page_slugs();
	if ( in_array( $url, $all_page_slug, true ) ) {
		$redirect_url = site_url( $url );
	} else {
		$redirect_url = ur_generate_signed_logout_url( $url );
	}

	return $redirect_url;
}

if ( ! function_exists( 'user_registration_form_settings_field' ) ) {

	/**
	 * Outputs a form fields on registration form settings.
	 *
	 * @param string $key Key.
	 * @param mixed  $args Arguments.
	 * @param string $value Value (default: null).
	 * @param string $current_row Current Row (default: empty).
	 * @param string $is_edit Is edit flag.
	 *
	 * @return string
	 */
	function user_registration_form_settings_field( $key, $args, $value = null, $current_row = '', $is_edit = false ) {
		/* Conditional Logic codes */
		$rules                      = array();
		$rules['conditional_rules'] = isset( $args['conditional_rules'] ) ? $args['conditional_rules'] : '';
		$rules['logic_gate']        = isset( $args['logic_gate'] ) ? $args['logic_gate'] : '';
		$rules['rules']             = isset( $args['rules'] ) ? $args['rules'] : array();
		$rules['required']          = isset( $args['required'] ) ? $args['required'] : '';

		foreach ( $rules['rules'] as $rules_key => $rule ) {
			if ( empty( $rule['field'] ) ) {
				unset( $rules['rules'][ $rules_key ] );
			}
		}

		$required = '';

		$rules['rules'] = array_values( $rules['rules'] );

		$rules = ( ! empty( $rules['rules'] ) && isset( $args['enable_conditional_logic'] ) ) ? wp_json_encode( $rules ) : '';
		/*Conditonal Logic codes end*/

		$defaults = array(
			'type'              => 'text',
			'label'             => '',
			'description'       => '',
			'placeholder'       => '',
			'size'              => false,
			'min'               => false,
			'max'               => false,
			'required'          => false,
			'autocomplete'      => false,
			'id'                => $key,
			'class'             => array(),
			'input_class'       => array(),
			'return'            => false,
			'options'           => array(),
			'custom_attributes' => array(),
			'validate'          => array(),
			'default'           => '',
			'autofocus'         => '',
			'priority'          => '',
		);

		$args = wp_parse_args( $args, $defaults );
		/**
		 * Filters the arguments for a user registration form field.
		 *
		 * The 'user_registration_form_field_args' filter allows developers to modify
		 * the arguments (args) for a specific form field during the user registration
		 * process. It provides an opportunity to customize the field arguments based on
		 * the original args, field key, and field value.
		 *
		 * @param array $args The original arguments for the form field.
		 * @param string $key The key identifying the form field.
		 * @param mixed $value The value of the form field.
		 */
		$args = apply_filters( 'user_registration_form_field_args', $args, $key, $value );

		if ( true === ur_string_to_bool( $args['required'] ) ) {
			$args['class'][]                       = 'validate-required';
			$args['custom_attributes']['required'] = 'required';
			$required                              = ' <abbr class="required" title="' . esc_attr__( 'required', 'user-registration' ) . '">*</abbr>';
		} else {
			$args['required'] = '';
		}

		if ( ( is_null( $value ) || empty( $value ) ) && ! is_numeric( $value ) ) {
			$value = $args['default'];
		}

		// Custom attribute handling.
		$custom_attributes         = array();
		$args['custom_attributes'] = array_filter( (array) $args['custom_attributes'] );

		if ( $args['size'] ) {
			$args['custom_attributes']['maxlength'] = absint( $args['size'] );
		}

		if ( ! empty( $args['min'] ) || '0' === $args['min'] ) {
			$args['custom_attributes']['min'] = $args['min'];
		}

		if ( isset( $args['min-characters'] ) ) {
			if ( ! empty( $args['min-characters'] ) || '0' === $args['min-characters'] ) {
				$args['custom_attributes']['minlength'] = $args['min-characters'];
			}
		}

		if ( isset( $args['max-characters'] ) ) {
			if ( ! empty( $args['max-characters'] ) || '0' === $args['max-characters'] ) {
				$args['custom_attributes']['maxlength'] = $args['max-characters'];
			}
		}

		if ( isset( $args['min-words'] ) ) {
			if ( ! empty( $args['min-words'] ) || '0' === $args['min-words'] ) {
				$args['custom_attributes']['data-min-words'] = $args['min-words'];
			}
		}

		if ( isset( $args['max-words'] ) ) {
			if ( ! empty( $args['max-words'] ) || '0' === $args['max-words'] ) {
				$args['custom_attributes']['max-words'] = $args['max-words'];
			}
		}

		if ( ! empty( $args['max'] ) || '0' === $args['max'] ) {
			$args['custom_attributes']['max'] = $args['max'];
		}

		if ( ! empty( $args['step'] ) ) {
			$args['custom_attributes']['step'] = $args['step'];
		}

		if ( ! empty( $args['autocomplete'] ) ) {
			$args['custom_attributes']['autocomplete'] = $args['autocomplete'];
		}

		if ( true === $args['autofocus'] ) {
			$args['custom_attributes']['autofocus'] = 'autofocus';
		}

		if ( isset( $args['default_value'] ) && ! empty( $args['default_value'] ) ) {
			$args['custom_attributes']['data-default'] = is_array( $args['default_value'] ) ? implode( ', ', $args['default_value'] ) : $args['default_value'];
		}

		if ( isset( $args['default'] ) && ! empty( $args['default'] ) ) {
			$args['custom_attributes']['data-default'] = $args['default'];
		}

		if ( ! empty( $args['custom_attributes'] ) && is_array( $args['custom_attributes'] ) ) {
			foreach ( $args['custom_attributes'] as $attribute => $attribute_value ) {
				$attribute_value     = is_array( $attribute_value ) ? json_encode( $attribute_value ) : $attribute_value;
				$custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
			}
		}

		if ( ! empty( $args['validate'] ) ) {
			foreach ( $args['validate'] as $validate ) {
				$args['class'][] = 'validate-' . $validate;
			}
		}

		$tooltip_html = '';

		if ( isset( $args['tooltip'] ) && ur_string_to_bool( $args['tooltip'] ) ) {
			$tooltip_html = ur_help_tip( $args['tooltip_message'], false, 'ur-portal-tooltip' );
		} elseif ( isset( $args['tip'] ) ) {
			$tooltip_html = ur_help_tip( $args['tip'], false, 'user-registration-help-tip tooltipstered' );
		}

		$cl_html = '';

		if ( isset( $args['enable_conditional_logic'] ) && true === $args['enable_conditional_logic'] ) {
			$cl_map  = isset( $args['cl_map'] ) ? $args['cl_map'] : '';
			$cl_html = sprintf( 'data-conditional-logic-enabled="1" data-conditional-logic-map="%s"', esc_attr( $cl_map ) );
		}

		$field = '';

		if ( 'section' === $args['type'] ) {
			$field  = '<div class="ur-form-settings-section--field">';
			$field .= '<h4>' . esc_html( $args['title'] ) . '</h4>';
			$field .= '</div>';
		}

		$label_id        = $args['id'];
		$sort            = $args['priority'] ? $args['priority'] : '';
		$field_container = '<div class="form-row %1$s" id="%2$s" data-priority="' . esc_attr( $sort ) . '" ' . $cl_html . '>%3$s</div>';
		$class           = '';
		if ( ! is_admin() ) {
			$form_id = isset( $args['form_id'] ) ? $args['form_id'] : '';
			/**
			 * Filters the enabled class for the icon associated with a user registration form field.
			 *
			 * The 'user_registration_field_icon_enabled_class' filter allows developers to modify
			 * the class name representing the enabled state of the icon associated with a form field.
			 * It provides an opportunity to customize the enabled class based on the original class
			 * and the form ID.
			 *
			 * @param string $class The original class representing the enabled state of the icon.
			 * @param int $form_id The ID of the user registration form.
			 */
			$class = apply_filters( 'user_registration_field_icon_enabled_class', $class, $form_id );
		}

		switch ( $args['type'] ) {

			case 'title':
				$field .= '<h4>' . esc_html( $args['title'] ) . '</h4>';
				break;

			case 'textarea':
				$field .= '<div class="ur-settings-field">';
				$field .= '<textarea style="margin-bottom:0px;" data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="input-text ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '" ' . ( empty( $args['custom_attributes']['rows'] ) ? ' rows="2"' : '' ) . ( empty( $args['custom_attributes']['cols'] ) ? ' cols="5"' : '' ) . implode( ' ', $custom_attributes ) . '>' . esc_textarea( $value ) . '</textarea>';
				$field .= '<div style="text-align: right; font-size:14px; color:#737373; margin-top:0px;">';
				$field .= '</div>';
				if ( $args['description'] ) {
					$field .= '<span class="description">' . $args['description'] . '</span>';
				}

				$field .= '</div>';
				break;

			case 'toggle':
				$default_value     = isset( $args['default_value'] ) ? $args['default_value'] : '';    // Backward compatibility. Modified since 1.5.7.
				$value             = ! empty( $value ) ? $value : $default_value;
				$select_all        = isset( $args['select_all'] ) ? ur_string_to_bool( $args['select_all'] ) : false;
				$options           = isset( $args['options'] ) ? $args['options'] : ( $args['choices'] ? $args['choices'] : array() ); // $args['choices'] for backward compatibility. Modified since 1.5.7.
				$choice_limit      = isset( $args['choice_limit'] ) ? $args['choice_limit'] : '';
				$choice_limit_attr = '';

				$field  = '<div class="ur-toggle-section ur-form-builder-toggle">';
				$field .= '<label class="ur-label checkbox" for="' . esc_attr( $key ) . '">' . $args['label'] . wp_kses_post( $tooltip_html ) . '</label>';
				$field .= '<div class="ur-settings-field">';
				$field .= '<span class="user-registration-toggle-form">';
				$field .= '<input data-id="' . esc_attr( $key ) . '" ' . implode( ' ', $custom_attributes ) . ' data-value="' . ur_string_to_bool( $value ) . '" type="checkbox" class="input-checkbox ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" value="1" ' . checked( ur_string_to_bool( $value ), 1, false ) . ' />';
				$field .= '<span class="slider round"></span>';
				$field .= '</span>';

				if ( $args['description'] ) {
					$field .= '<span class="description">' . $args['description'] . '</span>';
				}

				if ( isset( $args['notice'] ) && ! empty( $args['notice'] ) ) {
					$notice_type    = isset( $args['notice']['type'] ) ? $args['notice']['type'] : 'info';
					$notice_message = isset( $args['notice']['message'] ) ? $args['notice']['message'] : '';
					$field         .= '<span class="ur-settings-notice ur-settings-notice--' . $notice_type . '">' . $notice_message . '</span>';
				}

				$field .= '</div>';
				$field .= '</div>';
				break;

			case 'label':
				$field  = '<div class="' . esc_attr( $args['id'] ) . '">';
				$field .= '<span class="description">' . $args['description'] . '</span>';
				$field .= '</div>';
				break;
			case 'radio-group':
				$default_value = isset( $args['default_value'] ) ? $args['default_value'] : '';    // Backward compatibility. Modified since 1.5.7.
				$default       = ! empty( $value ) ? $value : $default_value;
				$select_all    = isset( $args['select_all'] ) ? ur_string_to_bool( $args['select_all'] ) : false;
				$options       = isset( $args['options'] ) ? $args['options'] : ( $args['choices'] ? $args['choices'] : array() ); // $args['choices'] for backward compatibility. Modified since 1.5.7.
				$choice_limit  = isset( $args['choice_limit'] ) ? $args['choice_limit'] : '';

				$field .= '<div class="ur-settings-field">';
				if ( ! empty( $args['options'] ) ) {
					$field .= '<ul class="ur-radio-group-list">';
					foreach ( $args['options'] as $option_index => $option_text ) {
						$class = str_replace( ' ', '-', strtolower( $option_text ) );

						$field .= '<li class="ur-radio-group-list--item  ' . $class . ( trim( $option_index ) === $value ? ' active' : '' ) . '">';

						$checked = '';

						if ( '' !== $value ) {
							$checked = checked( $value, trim( $option_index ), false );
						}

						$field .= '<label for="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_text ) . '" class="radio">';

						$field .= wp_kses(
							trim( $option_text ),
							array(
								'a'    => array(
									'href'  => array(),
									'title' => array(),
								),
								'span' => array(),
							)
						);

						$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="radio" class="input-radio ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" value="' . esc_attr( trim( $option_index ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_text ) . '" ' . implode( ' ', $custom_attributes ) . ' / ' . $checked . ' /> ';
						$field .= '</label>';

						$field .= '</li>';
					}
					$field .= '</ul>';
				}
				if ( $args['description'] ) {
					$field .= '<span class="description">' . $args['description'] . '</span>';
				}

				$field .= '</div>';
				break;

			case 'text':
			case 'email':
			case 'tel':
			case 'number':
			case 'url':
			case 'file':
			case 'timepicker':
				$extra_params_key    = str_replace( 'user_registration_', 'ur_', $key ) . '_params';
				$extra_params        = json_decode( get_user_meta( get_current_user_id(), $extra_params_key, true ) );
				$current_time        = isset( $args['current_time'] ) ? $args['current_time'] : '';
				$time_interval       = isset( $args['time_interval'] ) ? $args['time_interval'] : '';
				$time_format         = isset( $args['time_format'] ) ? $args['time_format'] : '';
				$time_range          = isset( $args['time_range'] ) ? $args['time_range'] : '';
				$time_min            = isset( $args['time_min'] ) ? $args['time_min'] : '';
				$time_max            = isset( $args['time_max'] ) ? $args['time_max'] : '';
				$username_length     = isset( $args['username_length'] ) ? $args['username_length'] : '';
				$username_character  = isset( $args['username_character'] ) ? $args['username_character'] : '';
				$time_slot_booking   = isset( $args['enable_time_slot_booking'] ) ? $args['enable_time_slot_booking'] : '';
				$target_date_field   = isset( $args['target_date_field'] ) ? isset( $args['target_date_field'] ) : '';
				$enable_calculations = $args['enable_calculations'] ?? '';
				$calculation_formula = $args['calculation_formula'] ?? '';
				$decimal_places      = $args['decimal_places'] ?? '';
				$attr                = '';

				$field .= '<div class="ur-settings-field">';

				if ( '' !== $username_length ) {
					$attr .= 'data-username-length="' . $username_length . '"';
				}

				if ( $username_character ) {
					$attr .= 'data-username-character="' . $username_character . '"';
				}

				if ( '' !== $time_interval ) {
					$attr .= 'data-time-interval="' . $time_interval . '"';
				}

				if ( '' !== $time_format ) {
					$attr .= 'data-time-format="' . $time_format . '"';
				}

				if ( '' !== $time_min ) {
					$attr .= 'data-time-min="' . $time_min . '"';
				}

				if ( '' !== $time_max ) {
					$attr .= 'data-time-max="' . $time_max . '"';
				}

				if ( $time_range ) {
					$attr .= 'data-time-range="' . $time_range . '"';
				}

				if ( $current_time ) {
					$attr .= 'data-current-time="' . $current_time . '"';
				}
				if ( '' !== $enable_calculations && $enable_calculations ) {
					$attr .= 'readonly data-decimal-places="' . esc_attr( $decimal_places ) . '" data-calculation-formula="' . esc_attr( $calculation_formula ) . '"';
				}
				if ( ur_string_to_bool( $time_slot_booking ) ) {
					$target_date_field = isset( $args['target_date_field'] ) ? $args['target_date_field'] : '';

					$attr  .= 'data-enable-time-slot-booking="' . $time_slot_booking . '"';
					$attr  .= 'data-target-date-field="' . $target_date_field . '"';
					$class .= ' time-slot-booking ';
				}

				$field .= ' <span class="input-wrapper"> ';
				if ( isset( $args['autocomplete_address'] ) && ur_string_to_bool( $args['autocomplete_address'] ) ) {
					$attr .= 'data-autocomplete-address="' . ur_string_to_bool( $args['autocomplete_address'] ) . '"';
					$attr .= 'data-address-style="' . $args['address_style'] . '"';
					$attr .= 'data-current-location="' . ur_option_checked( 'user_registration_google_map_current_location', false ) . '"';
					if ( 'map' == $args['address_style'] ) {
						$field .= '<div id="ur-geolocation-map" class="ur-geolocation-map"></div>';
					}
				}

				$timpicker_class = '';
				if ( 'timepicker' === $args['type'] ) {
					$timpicker_class = 'ur-timepicker';
				}

				if ( empty( $extra_params ) ) {
					if ( $time_range ) {
						// Extract the start and end time if the time is given in range.
						$pattern = '/^(\d{1,2}:\d{2}(?:\s?[APap][Mm])?)\s+to\s+(\d{1,2}:\d{2}(?:\s?[APap][Mm])?)$/';

						$start_time = '';
						$end_time   = '';

						if ( preg_match( $pattern, $value, $times ) ) {
							$start_time = $times[1];
							$end_time   = $times[2];
						}
						$field .= '<div class = "ur-timepicker-range">';
						$field .= '<input data-range-type="start" data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '-start" type="' . esc_attr( $args['type'] ) . '" class="input-text timepicker-start ' . esc_attr( $timpicker_class ) . ' ' . $class . ' input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '-start" id="' . esc_attr( $args['id'] ) . '" placeholder="Start Time "  value="' . esc_attr( $start_time ? $start_time : $value ) . '" ' . implode( ' ', $custom_attributes ) . ' ' . $attr . '/>';
						$field .= '<input data-range-type="end" data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '-end" class="input-text timepicker-end ' . esc_attr( $timpicker_class ) . ' ' . $class . ' input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '-end" id="' . esc_attr( $args['id'] ) . '-end" placeholder="End Time"  value="' . esc_attr( $end_time ? $end_time : $value ) . '" ' . implode( ' ', $custom_attributes ) . ' ' . $attr . '/>';
						$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="hidden" class="input-text timepicker-time ' . esc_attr( $timpicker_class ) . ' ' . $class . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" />';
						$field .= '</div>';
					} else {

						$disabled = ( ( ( isset( $_REQUEST['page'] ) && isset( $args['field_key'] ) && 'user-registration-members' == $_REQUEST['page'] ) && 'user_email' === $args['field_key'] ) || ( isset( $args['repeater_field'] ) && $args['repeater_field'] ) ) ? ' readonly="readonly"' : '';

						$field .= '<input ' . $disabled . ' data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="' . esc_attr( $args['type'] ) . '" class="input-text ' . esc_attr( $timpicker_class ) . ' ' . $class . ' input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" ' . implode( ' ', $custom_attributes ) . ' ' . $attr . '/>';
					}
				} elseif ( ! empty( $extra_params ) ) {
					if ( $time_range ) {
						$field .= '<input data-range-type="start" data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '-start-test" type="' . esc_attr( $args['type'] ) . '" class="input-text ' . esc_attr( $timpicker_class ) . ' ' . $class . ' input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '-start" id="' . esc_attr( $args['id'] ) . '-start" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" ' . implode( ' ', $custom_attributes ) . ' ' . $attr . '/>';
						$field .= '<input data-range-type="end" data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '-end" class="input-text timepicker-end ' . esc_attr( $timpicker_class ) . ' ' . $class . ' input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '-end" id="' . esc_attr( $args['id'] ) . '-end" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" ' . implode( ' ', $custom_attributes ) . ' ' . $attr . '/>';
						$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="hidden" class="input-text timepicker-time ' . $class . ' input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" />';
					} else {
						$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="' . esc_attr( $args['type'] ) . '" class="input-text ' . esc_attr( $timpicker_class ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" ' . implode( ' ', $custom_attributes ) . ' ' . $attr . ' />';
					}
				}

				if ( isset( $args['field_key'] ) && 'user_email' === $args['field_key'] ) {

					$user_id       = ! empty( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : get_current_user_id();
					$pending_email = get_user_meta( $user_id, 'user_registration_pending_email', true );
					$expiration    = get_user_meta( $user_id, 'user_registration_pending_email_expiration', true );
					$cancel_url    = esc_url(
						add_query_arg(
							array(
								'cancel_email_change' => $user_id,
								'_wpnonce'            => wp_create_nonce( 'cancel_email_change_nonce' ),
							),
							ur_get_my_account_url() . get_option( 'user_registration_myaccount_edit_profile_endpoint', 'edit-profile' )
						)
					);

					if ( ! empty( $pending_email ) && time() <= $expiration ) {
						$field .= sprintf(
						/* translators: %s - Email Change Pending Message. */
							'<div class="email-updated inline"><p>%s</p></div>',
							sprintf(
							/* translators: 1: Pending email message 2: Cancel Link */
								__( 'There is a pending change of your email to <code>%1$s</code>. <a href="%2$s">Cancel</a>', 'user-registration' ),
								$pending_email,
								$cancel_url
							)
						);

					} else {
						// Remove the confirmation key, pending email and expiry date.
						UR_Form_Handler::delete_pending_email_change( $user_id );
					}
				}

				if ( ! is_admin() ) {
					/**
					 * Filters the icon markup for a user registration form field.
					 *
					 * The 'user_registration_field_icon' filter allows developers to modify
					 * the icon markup associated with a specific form field during the user
					 * registration process. It provides an opportunity to customize the icon
					 * based on the original icon markup, form ID, and field arguments.
					 *
					 * @param string $field The original icon markup associated with the form field.
					 * @param int $form_id The ID of the user registration form.
					 * @param array $args The arguments for the form field.
					 */
					$field  = apply_filters( 'user_registration_field_icon', $field, $form_id, $args );
					$field .= ' </span> ';
				}

				if ( $args['description'] ) {
					$field .= '<span class="description">' . $args['description'] . '</span>';
				}

				$field .= '</div>';
				break;

			case 'date':
				$field .= '<div class="ur-settings-field">';

				$extra_params_key  = str_replace( 'user_registration_', 'ur_', $key ) . '_params';
				$extra_params      = json_decode( get_user_meta( get_current_user_id(), $extra_params_key, true ) );
				$date_slot_booking = isset( $args['enable_date_slot_booking'] ) ? $args['enable_date_slot_booking'] : '';
				if ( ur_string_to_bool( $date_slot_booking ) ) {

					$custom_attributes[] = 'data-enable-date-slot-booking="' . $date_slot_booking . '"';
					$class              .= ' date-slot-booking';
				}

				$actual_value = $value;
				if ( isset( $args['custom_attributes']['data-date-format'] ) ) {
					$date_format  = $args['custom_attributes']['data-date-format'];
					$default_date = isset( $args['custom_attributes']['data-default-date'] ) ? $args['custom_attributes']['data-default-date'] : '';
					if ( empty( $value ) && ur_string_to_bool( $default_date ) ) {
						$value        = date_i18n( $date_format );
						$actual_value = date_i18n( $date_format );
					}
				}

				$field .= ' <span class="input-wrapper"> ';

				if ( empty( $extra_params ) ) {
					$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '" type="text" value="' . esc_attr( $actual_value ) . '" class="ur-flatpickr-field regular-text ' . esc_attr( $class ) . '" readonly placeholder="' . esc_attr( $args['placeholder'] ) . '" ' . implode( ' ', $custom_attributes ) . ' />';
					$field .= '<input type="hidden" id="formated_date" value="' . esc_attr( $value ) . '"/>';
					$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '" type="text" data-field-type="' . esc_attr( $args['type'] ) . '" value="' . esc_attr( $actual_value ) . '" class="input-text input-' . esc_attr( $args['type'] ) . ' ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  ' . implode( ' ', $custom_attributes ) . ' style="display:none"/>';
				} else {
					$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '" type="text" value="' . esc_attr( $actual_value ) . '"  class="ur-flatpickr-field regular-text ' . $class . '" readonly placeholder="' . esc_attr( $args['placeholder'] ) . '" ' . implode( ' ', $custom_attributes ) . ' />';
					$field .= '<input type="hidden" id="formated_date" value="' . esc_attr( $value ) . '"/>';
					$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '" type="text" data-field-type="' . esc_attr( $args['type'] ) . '" value="' . esc_attr( $actual_value ) . '" class="input-text ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  ' . implode( ' ', $custom_attributes ) . ' style="display:none" />';
				}

				if ( ! is_admin() ) {
					/**
					 * Filters the icon markup for a user registration form field.
					 *
					 * The 'user_registration_field_icon' filter allows developers to modify
					 * the icon markup associated with a specific form field during the user
					 * registration process. It provides an opportunity to customize the icon
					 * based on the original icon markup, form ID, and field arguments.
					 *
					 * @param string $field The original icon markup associated with the form field.
					 * @param int $form_id The ID of the user registration form.
					 * @param array $args The arguments for the form field.
					 */
					$field = apply_filters( 'user_registration_field_icon', $field, $form_id, $args );
				}
				$field .= '</span> ';
				if ( $args['description'] ) {
					$field .= '<span class="description">' . $args['description'] . '</span>';
				}

				$field .= '</div>';
				break;

			case 'color':
				$field .= '<div class="ur-settings-field">';
				$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="text" class="input-text input-color ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" data-default-color="' . esc_attr( $args['default'] ) . '" ' . implode( ' ', $custom_attributes ) . ' />';
				if ( $args['description'] ) {
					$field .= '<span class="description">' . $args['description'] . '</span>';
				}

				$field .= '</div>';
				break;

			case 'select':
				$default_value = isset( $args['default_value'] ) ? $args['default_value'] : ''; // Backward compatibility. Modified since 1.5.7.

				$value           = ! empty( $value ) ? $value : $default_value;
				$options         = $field .= '';
				$field          .= '<div class="ur-settings-field">';
				$backtrace       = debug_backtrace();
				$parent_function = isset( $backtrace[1] ) ? $backtrace[1]['function'] : '';
				$args['options'] = ( 'frontend_includes' === $parent_function ) ? apply_filters( 'override_options_for_select_field', $args['options'], $args['id'] ) : $args['options'];

				if ( ! empty( $args['options'] ) ) {
					// If we have a blank option, select2 needs a placeholder.
					if ( '' === $value && ! empty( $args['placeholder'] ) ) {
						$options .= '<option value="" selected disabled>' . esc_html( $args['placeholder'] ) . '</option>';
					}

					if ( isset( $args['field_key'] ) && 'country' === $args['field_key'] && empty( $args['placeholder'] ) && empty( $value ) ) {
						$options .= '<option value="" selected >' . esc_html__( 'Select a country', 'user-registration' ) . '</option>';
					}

					$custom_attributes[] = 'data-allow_clear="true"';
					foreach ( $args['options'] as $option_key => $option_text ) {
						$selected_attribute = '';

						if ( '' !== $value ) {
							$selected_attribute = selected( $value, trim( $option_key ), false );
						}
						$options .= '<option value="' . esc_attr( trim( $option_key ) ) . '" ' . $selected_attribute . '>' . esc_attr( trim( $option_text ) ) . '</option>';
					}

					$field .= '<select data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" class="select ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" ' . implode( ' ', $custom_attributes ) . ' data-placeholder="' . esc_attr( $args['placeholder'] ) . '">
							' . $options . '
						</select>';
				}
				if ( $args['description'] ) {
					$field .= '<span class="description">' . $args['description'] . '</span>';
				}

				$field .= '</div>';
				break;

			case 'multiselect':
				$options = $field .= '';
				$field  .= '<div class="ur-settings-field">';

				if ( is_serialized( $value ) ) {
					$default_value = unserialize( $value, array( 'allowed_classes' => false ) ); //phpcs:ignore;
				} else {
					$default_value = $value;
				}

				$args['options'] = apply_filters( 'override_options_for_select_field', $args['options'] );

				if ( ! empty( $args['options'] ) ) {
					foreach ( $args['options'] as $option_key => $option_text ) {

						if ( '' === $option_key ) {
							// If we have a blank option, select2 needs a placeholder.
							if ( empty( $args['placeholder'] ) ) {
								$args['placeholder'] = $option_text ? $option_text : __( 'Choose an option', 'user-registration' );
							}
							$custom_attributes[] = 'data-allow_clear="true"';
						}

						if ( is_array( $default_value ) ) {
							$options .= '<option value="' . esc_attr( trim( $option_key ) ) . '" ' . selected( in_array( trim( $option_key ), $default_value ), true, false ) . '>' . esc_attr( trim( $option_text ) ) . '</option>';
						} else {
							$options .= '<option value="' . esc_attr( trim( $option_key ) ) . '" ' . selected( $default_value, trim( $option_key ), false ) . '>' . esc_attr( trim( $option_text ) ) . '</option>';
						}
					}

					$field .= '<select multiple data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '[]" id="' . esc_attr( $args['id'] ) . '" class="select ur-enhanced-select' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" ' . implode( ' ', $custom_attributes ) . ' data-placeholder="' . esc_attr( $args['placeholder'] ) . '">
							' . $options . '
						</select>';
				}
				if ( $args['description'] ) {
					$field .= '<span class="description">' . $args['description'] . '</span>';
				}

				$field .= '</div>';
				break;

			case 'radio':
				$default_value = isset( $args['default_value'] ) ? $args['default_value'] : ''; // Backward compatibility. Modified since 1.5.7.
				$value         = ! empty( $value ) ? $value : $default_value;
				$label_id      = current( array_keys( $args['options'] ) );

				if ( empty( $args['options'] ) ) {
					return;
				}

				$field .= '<div class="ur-settings-field">';

				if ( isset( $args['image_choice'] ) && ur_string_to_bool( $args['image_choice'] ) ) {
					$field .= '<ul class="user-registration-image-options">';
					foreach ( $args['image_options'] as $option_index => $option_text ) {
						$option_label = is_array( $option_text ) ? $option_text['label'] : $option_text->label;
						$option_image = is_array( $option_text ) ? $option_text['image'] : $option_text->image;

						$field  .= '<li class="ur-radio-list">';
						$checked = '';
						if ( ! empty( $value ) ) {
							$checked = checked( $value, trim( $option_index ), false );
						}

						$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="radio" class="input-radio ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" value="' . esc_attr( trim( $option_index ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_label ) . '" ' . implode( ' ', $custom_attributes ) . ' / ' . $checked . ' /> ';
						$field .= '<label for="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_label ) . '" class="radio">';

						if ( ! empty( $option_image ) ) {
							$field .= '<span class="user-registration-image-choice">';
							$field .= '<img src="' . esc_url( $option_image ) . '" alt="' . esc_attr( trim( $option_label ) ) . '" width="200px">';
							$field .= '</span>';
						}

						$field .= wp_kses(
							trim( $option_label ),
							array(
								'a'    => array(
									'href'  => array(),
									'title' => array(),
								),
								'span' => array(),
							)
						) . '</label></li>';
					}
					$field .= '</ul>';
				} else {
					$field .= '<ul>';
					foreach ( $args['options'] as $option_index => $option_text ) {

						$field .= '<li class="ur-radio-list">';

						$checked = '';
						if ( ! empty( $value ) ) {
							$checked = checked( $value, trim( $option_index ), false );
						}

						$field .= '<input data-rules="' . esc_attr( $rules ) . '" data-id="' . esc_attr( $key ) . '" type="radio" class="input-radio ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" value="' . esc_attr( trim( $option_index ) ) . '"  name="' . esc_attr( $key ) . ( '' !== $current_row ? '_' . $current_row : '' ) . '" id="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_text ) . '" ' . implode( ' ', $custom_attributes ) . ' / ' . $checked . ' /> ';
						$field .= '<label for="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_text ) . '" class="radio">';

						$field .= wp_kses(
							trim( $option_text ),
							array(
								'a'    => array(
									'href'  => array(),
									'title' => array(),
								),
								'span' => array(),
							)
						) . '</label></li>';
					}
					$field .= '</ul>';
				}
				if ( $args['description'] ) {
					$field .= '<span class="description">' . $args['description'] . '</span>';
				}

				$field .= '</div>';
				break;

			case 'tinymce':
				$default_value = isset( $args['default_value'] ) ? $args['default_value'] : ''; // Backward compatibility. Modified since 1.5.7.

				$editor_settings = array(
					'name'       => esc_attr( $args['id'] ),
					'id'         => esc_attr( $args['id'] ),
					'style'      => esc_attr( $args['css'] ),
					'default'    => esc_attr( $args['default'] ),
					'class'      => esc_attr( $args['class'] ),
					'quicktags'  => array( 'buttons' => 'em,strong,link' ),
					'tinymce'    => array(
						'statusbar'               => false,
						'toolbar1'                => 'undo,redo,formatselect,fontselect,fontsizeselect,bold,italic,forecolor,alignleft,aligncenter,alignright,alignjustify,bullist,numlist,outdent,indent,removeformat',
						'toolbar2'                => '',
						'toolbar3'                => '',
						'toolbar4'                => '',
						'theme_advanced_buttons2' => '',
						'plugins'                 => 'wordpress,wpautoresize,wplink,wpdialogs,wptextpattern,wpview,colorpicker,textcolor,hr,charmap,link,fullscreen,lists',
					),
					'editor_css' => '<style>#wp-excerpt-editor-container .wp-editor-area{height:175px; width:100%;}</style>',
				);

				$value = ! empty( $value ) ? $value : $default_value;

				$field .= '<div class="user-registration-tinymce-field ' . $args['id'] . '">';

				// Output buffer for tinymce editor.
				ob_start();
				wp_editor( $value, $args['id'], $editor_settings );
				$field .= ob_get_clean();

				$field .= '</div>';

				break;

			case 'html':
				$content = isset( $args['html_content'] ) ? $args['html_content'] : '';

				$field .= $content;
				break;
		}

		if ( ! empty( $field ) ) {

			$field_html = '';
			if ( $args['label'] && 'checkbox' != $args['type'] && 'toggle' != $args['type'] && 'hidden' !== $args['type'] ) {
				$field_html .= '<label for="' . esc_attr( $label_id ) . '" class="ur-label">' . wp_kses(
					$args['label'],
					array(
						'a'    => array(
							'href'  => array(),
							'title' => array(),
						),
						'span' => array(),
					)
				) . $required . $tooltip_html . '</label>';
			}

			$field_html     .= $field;
			$container_class = esc_attr( implode( ' ', $args['class'] ) );
			$container_id    = esc_attr( $args['id'] ) . '_field';
			$field           = sprintf( $field_container, $container_class, $container_id, $field_html );
		}

		/**
		 * Filters the form field based on its type.
		 *
		 * The dynamic 'user_registration_form_field_{type}' filter allows developers to modify
		 * the form field for a specific type during the user registration process. The {type}
		 * placeholder is replaced with the actual field type, providing a flexible way to customize
		 * the form field based on its type, field key, arguments, and value.
		 *
		 * @param string $field The original form field markup for the specific type.
		 * @param string $key The key identifying the form field.
		 * @param array $args The arguments for the form field.
		 * @param mixed $value The value of the form field.
		 */
		$field = apply_filters( 'user_registration_form_field_' . $args['type'], $field, $key, $args, $value, $current_row );

		if ( $args['return'] ) {
			return $field;
		} else {
			echo $field; //PHPCS:ignore;
		}
	}
} // End if().
