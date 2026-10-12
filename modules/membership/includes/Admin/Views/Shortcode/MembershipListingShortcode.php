<?php

namespace WPEverest\URMembership\Admin\Views\Shortcode;

use WPEverest\URMembership\Admin\Members\MembersListTable;
use WPEverest\URMembership\Admin\Repositories\MembershipGroupRepository;
use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Services\MembershipGroupService;
use WPEverest\URMembership\Admin\Services\MembershipService;
use WPEverest\URMembership\Admin\Repositories\MembersRepository;

/**
 * Registration Shortcodes
 *
 * Show the Registration form
 *
 * @class    MembershipListingShortcode
 * @version  1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * UR_Shortcode_Login Class.
 */
class MembershipListingShortcode {


	/**
	 * Output the shortcode.
	 *
	 * @param array  $attributes Shortcode attributes.
	 * @param string $shortcode Shortcode itself.
	 */
	public static function render_template( $attributes, $shortcode ) {
		global $wp, $post;

		$membership_service = new MembershipService();
		$memberships        = $membership_service->list_active_memberships();

		$membership_repository      = new MembersRepository();
		$membershp_group_repository = new MembershipGroupRepository();
		$membership_group_service   = new MembershipGroupService();

		$type = 'user_registration_groups' === $shortcode ? 'list' : '';

		if ( ! empty( $attributes['list_type'] ) ) {
			$type = $attributes['list_type'];
		}

		$group_id = ! empty( $attributes['group_id'] ) ? $attributes['group_id'] : ( ! empty( $attributes['id'] ) ? $attributes['id'] : 0 );

		$selected_membership_ids = ! empty( $attributes['selected_membership_ids'] ) && is_array( $attributes['selected_membership_ids'] )
			? array_map( 'absint', $attributes['selected_membership_ids'] )
			: array();

		if ( 'selected-memberships' === $group_id && ! empty( $selected_membership_ids ) ) {
			$all_memberships = $membership_service->list_active_memberships();
			$memberships     = array_filter(
				$all_memberships,
				function ( $m ) use ( $selected_membership_ids ) {
					return isset( $m['ID'] ) && in_array( (int) $m['ID'], $selected_membership_ids, true );
				}
			);
			$memberships = array_values( $memberships );
			$memberships = array_map(
				function ( $membership ) use ( $membershp_group_repository, $membership_group_service ) {
					$membership_group_id = $membershp_group_repository->get_membership_group_by_membership_id( $membership['ID'] );
					if ( isset( $membership_group_id['ID'] ) ) {
						$multiple_memberships_allowed = $membership_group_service->check_if_multiple_memberships_allowed( $membership_group_id['ID'] );
						if ( $multiple_memberships_allowed ) {
							$membership['multiple_membership'] = true;
						}
					}
					return $membership;
				},
				$memberships
			);
		} elseif ( $group_id && is_numeric( $group_id ) ) {
			$group_id    = absint( $group_id );
			$memberships = $membership_group_service->get_group_memberships( $group_id );

			$multiple_memberships_allowed = $membership_group_service->check_if_multiple_memberships_allowed( $group_id );

			if ( $multiple_memberships_allowed ) {
				$memberships = array_map(
					function ( $membership ) {
							$membership['multiple_membership'] = true;
						return $membership;
					},
					$memberships
				);
			}
		} else {
			$memberships = array_map(
				function ( $membership ) use ( $membershp_group_repository, $membership_group_service ) {
					$membership_group_id = $membershp_group_repository->get_membership_group_by_membership_id( $membership['ID'] );

					if ( isset( $membership_group_id['ID'] ) ) {
						$multiple_memberships_allowed = $membership_group_service->check_if_multiple_memberships_allowed( $membership_group_id['ID'] );

						if ( $multiple_memberships_allowed ) {
							$membership['multiple_membership'] = true;
						}
					}
					return $membership;
				},
				$memberships
			);
		}

		$sign_up_text   = ! empty( $attributes['button_text'] ) ? esc_html__( sanitize_text_field( $attributes['button_text'] ), 'user-registration' ) : __( 'Sign Up', 'user-registration' );
		$action_to_take = 'upgrade';

		$currency             = get_option( 'user_registration_payment_currency', 'USD' );
		$currencies           = ur_payment_integration_get_currencies();
		$symbol               = $currencies[ $currency ]['symbol'];
		$registration_page_id = ur_get_translated_page_id( ! empty( $attributes['registration_page_id'] ) ? absint( $attributes['registration_page_id'] ) : get_option( 'user_registration_member_registration_page_id', false ) );
		$thank_you_page_id    = ur_get_translated_page_id( ! empty( $attributes['thank_you_page_id'] ) ? absint( $attributes['thank_you_page_id'] ) : get_option( 'user_registration_thank_you_page_id', false ) );
		$uuid                 = ! empty( $attributes['uuid'] ) ? $attributes['uuid'] : ur_generate_random_key();
		$redirect_page_url    = get_permalink( $registration_page_id );

		$current_user_id     = get_current_user_id();
		$user_membership_ids = array();

		if ( $current_user_id ) {
			$user_memberships    = $membership_repository->get_member_membership_by_id( $current_user_id );
			$user_membership_ids = array_filter(
				array_map(
					function ( $user_memberships ) {
						return $user_memberships['post_id'];
					},
					$user_memberships
				)
			);
		}

		$column_number    = isset( $attributes['column_number'] ) ? $attributes['column_number'] : 0;
		$open_in_new_tab  = isset( $attributes['open_in_new_tab'] ) ? $attributes['open_in_new_tab'] : false;
		$show_description = isset( $attributes['show_description'] ) ? $attributes['show_description'] : false;

		$is_editor = false;
		if ( function_exists( 'wp_is_block_editor' ) && wp_is_block_editor() ) {
			$is_editor = true;
		}

		$style = $attributes['style'] ?? '';

		$uuid = ! empty( $attributes['uuid'] ) ? sanitize_key( $attributes['uuid'] ) : ( ! empty( $attributes['id'] ) ? sanitize_key( $attributes['id'] ) : ur_generate_random_key() );

		$button_class = 'ur-membership-signup-btn-' . sanitize_html_class( $uuid );
		$radio_class  = 'ur-membership-radio-' . sanitize_html_class( $uuid );

		$button_style       = '';
		$button_hover_style = '';
		$radio_css          = '';

		$map = array(
			'buttonTextColor' => 'color',
			'buttonBgColor'   => 'background',
			'buttonFontSize'  => 'font-size',
		);

		foreach ( $map as $key => $css ) {
			if ( ! empty( $style[ $key ] ) ) {
				$button_style .= "{$css}:{$style[$key]};";
			}
		}

		// Typography (nested array)
		if ( ! empty( $style['buttonTypography']['fontWeight'] ) ) {
			$button_style .= 'font-weight:' . $style['buttonTypography']['fontWeight'] . ';';
		}

		if ( ! empty( $style['buttonTypography']['fontStyle'] ) ) {
			$button_style .= 'font-style:' . $style['buttonTypography']['fontStyle'] . ';';
		}

		// Padding loop
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $pos ) {
			if ( ! empty( $style['buttonPadding'][ $pos ] ) ) {
				$button_style .= "padding-$pos:" . $style['buttonPadding'][ $pos ] . ';';
			}
		}

		// Margin loop
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $pos ) {
			if ( ! empty( $style['buttonMargin'][ $pos ] ) ) {
				$button_style .= "margin-$pos:" . $style['buttonMargin'][ $pos ] . ';';
			}
		}

		// Hover colors
		if ( ! empty( $style['buttonTextHoverColor'] ) ) {
			$button_hover_style .= 'color:' . $style['buttonTextHoverColor'] . ' !important;';
		}
		if ( ! empty( $style['buttonBgHoverColor'] ) ) {
			$button_hover_style .= 'background:' . $style['buttonBgHoverColor'] . ' !important;';
		}

		// radio color
		$radio_color = isset( $style['radioColor'] ) ? $style['radioColor'] : '';

		if ( $radio_color ) {
			$radio_css .= 'accent-color:' . $radio_color . ';';
		}

		if ( empty( $memberships ) ) {
			echo wp_kses_post( apply_filters( 'user_registration_membership_no_membership_message', __( 'Empty membership group.', 'user-registration' ) ) );

			return;
		}

		$template_file = locate_template( 'membership-listing.php' );

		if ( ! $template_file ) {
			$template_file = UR_MEMBERSHIP_DIR . 'includes/Templates/membership-listing.php';
		}
		require $template_file;
	}
}
