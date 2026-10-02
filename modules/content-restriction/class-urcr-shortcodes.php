<?php
/**
 * User Registration Content Restriction Shortcodes.
 *
 * @class    URCR_Shortcodes
 * @version  4.0
 * @package  UserRegistrationContentRestriction/Classes
 * @package Class
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URCR_Shortcodes Class
 */
class URCR_Shortcodes {

	/**
	 * Constructor.
	 * @return void
	 * @access public
	 */
	public function __construct() {
		add_shortcode( 'urcr_restrict', array( $this, 'urcr_restrict_shortcode' ) );
	}

	public function urcr_restrict_shortcode( $atts, $content = null ) {
		global $post;
		/** For divi builder edit
		 * global $post will be the empty on edit mode.
		 *
		 * @since 4.2.1
		 */

		if ( empty( $post ) && ( function_exists( 'urm_is_divi_active' ) && urm_is_divi_active() ) ) {
			$post = isset( $atts['post_id'] ) ? get_post( absint( $atts['post_id'] ) ) : null;
		}

		if ( ! is_object( $post ) ) {
			return;
		}

		$enable_disable = ur_string_to_bool( get_option( 'user_registration_content_restriction_enable', true ) );
		if ( $enable_disable ) {
			if ( function_exists( 'is_super_admin' ) && is_super_admin() ) {
				return do_shortcode( $content );
			}
			$global_migrated = get_option( 'urcr_global_restriction_migrated', false );

			$override_global_settings = get_post_meta( $post->ID, 'urcr_meta_override_global_settings', $single = true );

			$rule = urcr_migrated_global_rule();
			if ( $global_migrated && ! empty( $rule ) ) {

				$allowed_roles = isset( $rule['logic_map'], $rule['logic_map']['conditions'], $rule['logic_map']['conditions'][0], $rule['logic_map']['conditions'][0]['type'], $rule['logic_map']['conditions'][0]['value'] ) && 'roles' === $rule['logic_map']['conditions'][0]['type'] ? $rule['logic_map']['conditions'][0]['value'] : 'administrator';

				$allowed_memberships = isset( $rule['logic_map'], $rule['logic_map']['conditions'], $rule['logic_map']['conditions'][0], $rule['logic_map']['conditions'][0]['type'], $rule['logic_map']['conditions'][0]['value'] ) && 'memberships' === $rule['logic_map']['conditions'][0]['type'] ? $rule['logic_map']['conditions'][0]['value'] : '';

				$message = isset( $rule['actions'], $rule['actions'][0], $rule['actions'][0]['message'] ) ? $rule['actions'][0]['message'] : __( 'This content is restricted!', 'user-registration' );

			} else {

				$allowed_roles       = get_option( 'user_registration_content_restriction_allow_to_roles', 'administrator' );
				$allowed_memberships = get_option( 'user_registration_content_restriction_allow_to_memberships' );
				$message             = get_option( 'user_registration_content_restriction_message', '' );
			}

			$get_meta_data_memberships = get_post_meta( $post->ID, 'urcr_meta_memberships', true );
			$get_meta_data_roles       = get_post_meta( $post->ID, 'urcr_meta_roles', $single = true );
			$current_user_roles        = ( is_user_logged_in() && ! empty( wp_get_current_user()->roles ) ) ? (array) wp_get_current_user()->roles : array( 'guest' );

			foreach ( $atts as $key => $value ) {
				if ( is_string( $value ) ) {
					$value        = html_entity_decode( $value, ENT_QUOTES );
					$value        = str_replace( array( '“', '”' ), '"', $value );
					$atts[ $key ] = trim( $value, '"' );
				}
			}

			if ( isset( $atts['access_specific_role'] ) && ! empty( $atts['access_specific_role'] ) && 'choose_specific_roles' === $atts['access_all_roles'] ) {
				$specific_roles = $atts['access_specific_role'];
			}

			if ( isset( $atts['access_membership_role'] ) && ! empty( $atts['access_membership_role'] ) && 'memberships' === $atts['access_all_roles'] ) {
				$memberships_roles = $atts['access_membership_role'];
			}

			$access_control = isset( $atts['access_control'] ) ? trim( $atts['access_control'] ) : '';

			$roles = isset( $atts['access_role'] ) ? trim( $atts['access_role'] ) : '';

			if ( isset( $atts['access_all_roles'], $atts['enable_content_restriction'] ) && ! empty( $atts['access_all_roles'] ) && true == $atts['enable_content_restriction'] ) {
				$roles = trim( $atts['access_all_roles'] );
			}

			$is_membership_active = ur_check_module_activation( 'membership' );

			if ( $is_membership_active ) {
				$members_subscription = new \WPEverest\URMembership\Admin\Repositories\MembersSubscriptionRepository();
				$subscription         = $members_subscription->get_member_subscription( wp_get_current_user()->ID );

				$current_user_membership   = array();
				$is_user_membership_active = false;

				if ( ! empty( $subscription ) && is_array( $subscription ) ) {
					foreach ( $subscription as $sub ) {
						if ( ! empty( $sub['item_id'] ) ) {
							$current_user_membership[] = $sub['item_id'];
						}
						if ( ! empty( $sub['status'] ) && in_array( $sub['status'], array( 'active', 'trial' ), true ) ) {
							$is_user_membership_active = true;
						}
					}
				}
			}

			if ( empty( $roles ) ) {
				$override_global_settings = get_post_meta( $post->ID, 'urcr_meta_override_global_settings', $single = true );

				if ( 'on' !== $override_global_settings ) {

					if ( '0' == get_option( 'user_registration_content_restriction_allow_access_to', '0' ) ) {
						if ( is_user_logged_in() ) {
							return do_shortcode( $content );
						}
					} elseif ( '1' == get_option( 'user_registration_content_restriction_allow_access_to' ) ) {
						if ( is_array( $allowed_roles ) && array_intersect( $current_user_roles, (array) $allowed_roles ) ) {
							return do_shortcode( $content );
						}
					} elseif ( '2' === get_option( 'user_registration_content_restriction_allow_access_to' ) ) {
						if ( ! is_user_logged_in() ) {
							return do_shortcode( $content );
						}
					} elseif ( '3' === get_option( 'user_registration_content_restriction_allow_access_to' ) ) {
						if ( $is_membership_active && is_array( $allowed_memberships ) && urm_check_user_membership_has_access( $allowed_memberships ) ) {
							return do_shortcode( $content );
						}
					}
				} else {
					$get_meta_data_allow_to = get_post_meta( $post->ID, 'urcr_allow_to', $single = true );

					switch ( $get_meta_data_allow_to ) {
						case '0':
							if ( is_user_logged_in() ) {
								return do_shortcode( $content );
							}
							break;
						case '1':
							if ( isset( $get_meta_data_roles ) && ! empty( $get_meta_data_roles ) ) {
								if ( is_array( $get_meta_data_roles ) && array_intersect( $current_user_roles, (array) $get_meta_data_roles ) ) {
									return do_shortcode( $content );
								}
							}
							break;
						case '2':
							if ( ! is_user_logged_in() ) {
								return do_shortcode( $content );
							}
							break;
						case '3':
							if ( ! empty( $get_meta_data_memberships ) ) {
								if ( $is_membership_active && is_array( $get_meta_data_memberships ) && urm_check_user_membership_has_access( $get_meta_data_memberships ) ) {
									return do_shortcode( $content );
								}
							}
							break;
					}
				}
			}

			$specific_roles = isset( $specific_roles ) ? explode( ',', $specific_roles ) : array();
			$specific_roles = array_map( 'trim', $specific_roles );

			$memberships_roles = isset( $memberships_roles ) ? explode( ',', $memberships_roles ) : array();
			$memberships_roles = array_map(
				function ( $role ) {
					return trim( str_replace( '″', '', $role ) );
				},
				$memberships_roles
			);

			if ( 'on' === $override_global_settings ) {
				$urcr_meta_content = get_post_meta( $post->ID, 'urcr_meta_content', true );
				$message = ! empty( $urcr_meta_content ) ? wp_kses_post( $urcr_meta_content ) : '';
			} elseif ( isset( $atts['enable_content_restriction'] ) && $atts['enable_content_restriction'] === 'true' ) {
				$message = isset( $atts['message'] ) ? wp_kses_post( html_entity_decode( $atts['message'] ) ) : $message;
			}

			$message = empty( $message ) ? __( 'This content is restricted1!', 'user-registration' ) : $message;
			$message = apply_filters( 'user_registration_process_smart_tags', $message );
			$message = do_shortcode( $message );

			if ( isset( $atts['enable_content_restriction'] ) && $atts['enable_content_restriction'] == true ) {
				$control_type = $atts['access_control'] ?? 'access';
				$matched      = false;

				switch ( $roles ) {
					case 'all_logged_in_users':
						$matched = is_user_logged_in();
						break;

					case 'choose_specific_roles':
						if ( ! empty( $specific_roles ) && array_intersect( $current_user_roles, (array) $specific_roles ) ) {
							$matched = true;
						}
						break;

					case 'guest_users':
						$matched = ! is_user_logged_in();
						break;

					case 'memberships':
						if ( ! empty( $memberships_roles ) && is_array( $current_user_membership ) && $is_user_membership_active ) {
							$common = array_intersect( $current_user_membership, $memberships_roles );
							if ( ! empty( $common ) ) {
								$matched = true;
							}
						}
						break;
				}

				// Apply access_control logic
				if (
					( $control_type === 'access' && $matched ) ||
					( $control_type === 'restrict' && ! $matched )
				) {
					return do_shortcode( $content );
				}
			}

			// Get URLs for login and signup
			$login_page_id        = get_option( 'user_registration_login_page_id' );
			$registration_page_id = get_option( 'user_registration_member_registration_page_id' );

			$login_url  = $login_page_id ? get_permalink( $login_page_id ) : wp_login_url();
			$signup_url = $registration_page_id ? get_permalink( $registration_page_id ) : ( $login_page_id ? get_permalink( $login_page_id ) : wp_registration_url() );

			if ( ! $registration_page_id ) {
				$default_form_page_id = get_option( 'user_registration_default_form_page_id' );
				if ( $default_form_page_id ) {
					$signup_url = get_permalink( $default_form_page_id );
				}
			}

			// Use base template to generate styled content
			ob_start();
			urcr_get_template(
				'base-restriction-template.php',
				array(
					'message'    => $message,
					'login_url'  => $login_url,
					'signup_url' => $signup_url,
				)
			);
			$styled_content = ob_get_clean();

			return $styled_content;

		}
			return do_shortcode( $content );
	}
}
return new URCR_Shortcodes();
