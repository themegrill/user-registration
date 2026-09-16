<?php
/**
 * User registration membership buy now.
 *
 * @since xx.xx.xx
 * @package user-registration
 */

defined( 'ABSPATH' ) || exit;

use WPEverest\URMembership\Admin\Repositories\MembersRepository;
use WPEverest\URMembership\Admin\Repositories\MembershipRepository;
use WPEverest\URMembership\Admin\Services\MembershipService;

/**
 * Block Membership buy now class.
 */
class UR_Block_Membership_Buy_Now extends UR_Block_Abstract {

	protected $block_name = 'membership-buy-now';

	/** Parse preset color */
	private function parse_preset_color( $value ) {

		if ( strpos( $value, 'var:preset|color|' ) !== false ) {
			$slug = str_replace( 'var:preset|color|', '', $value );
			return 'var(--wp--preset--color--' . $slug . ')';
		} else if(  strpos( $value, 'accent-' !== false )  ) {
			return 'var(--wp--preset--color--' . $value . ')';
		}
		return $value;
	}

	/** Parse preset spacing */
	private function parse_preset_spacing( $value ) {
		if ( strpos( $value, 'var:preset|spacing|' ) !== false ) {
			$slug = str_replace( 'var:preset|spacing|', '', $value );
			return 'var(--wp--preset--spacing--' . $slug . ')';
		}
		return $value;
	}

	/**
	 * Build HTML.
	 */
	protected function build_html( $content ) {
		// This block renders the visitor's own plan state instead of routing through the membership wrapper.
		UR_Cache_Helper::disable_page_cache( 'membership' );

		wp_register_style(
			'user-registration-blocks-style',
			UR()->plugin_url() . '/chunks/blocks.css',
			array(),
			UR_VERSION
		);

		wp_enqueue_style( 'user-registration-blocks-style' );
		$block_id = isset( $this->attributes['clientId'] ) ? $this->attributes['clientId'] : '';
		$attr     = $this->attributes;

		$page_id = ur_get_translated_page_id( get_option( 'user_registration_member_registration_page_id' ) );

		$page_url = get_permalink( absint( $page_id ) );

		$is_editor = false;

		if ( function_exists( 'wp_is_block_editor' ) && wp_is_block_editor() ) {
			$is_editor = true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$is_editor = true;
		}

		$page_url = $is_editor ? '#' : $page_url;

		$style = isset( $attr['style'] ) ? $attr['style'] : array();

		$button_classes = 'urm-buy-now-btn urm-' . $block_id;
		if ( ! empty( $attr['className'] ) ) {
			$button_classes .= ' ' . $attr['className'];
		}


		$text_color_raw = '';
		if ( isset( $attr['textColor'] ) && ! empty( $attr['textColor'] ) ) {
			$text_color_raw = $attr['textColor'];
		} elseif ( isset( $style['elements']['link']['color']['text'] ) ) {
			$text_color_raw = $style['elements']['link']['color']['text'];
		}
		$text_color = $this->parse_preset_color( $text_color_raw );


		$background_raw = '';
		if ( isset( $attr['backgroundColor'] ) && ! empty( $attr['backgroundColor'] ) ) {
			$background_raw = $attr['backgroundColor'];
		} elseif ( isset( $style['color']['background'] ) ) {
			$background_raw = $style['color']['background'];
		}
		$background = $this->parse_preset_color( $background_raw );

		$text_hover_color    = isset( $attr['hoverTextColor'] ) ? $attr['hoverTextColor'] : '';
		$text_hover_bg_color = isset( $attr['hoverBgColor'] ) ? $attr['hoverBgColor'] : '';

		$border_color_raw = '';
		if ( isset( $attr['borderColor'] ) && ! empty( $attr['borderColor'] ) ) {
			$border_color_raw = $attr['borderColor'];
		} elseif ( isset( $style['border']['color'] ) ) {
			$border_color_raw = $style['border']['color'];
		}
		$border_color = $this->parse_preset_color( $border_color_raw );


		$border_width = '';
		if ( isset( $attr['borderWidth'] ) && ! empty( $attr['borderWidth'] ) ) {
			$border_width = $attr['borderWidth'];
		} elseif ( isset( $style['border']['width'] ) ) {
			$border_width = $style['border']['width'];
		}


		$border_style = isset( $attr['borderStyle'] ) ? $attr['borderStyle'] : 'solid';


		if ( isset( $attr['borderRadius'] ) && ! empty( $attr['borderRadius'] ) ) {

			$border_radius_value = $attr['borderRadius'];
			$radius              = array(
				'topLeft'     => $border_radius_value,
				'topRight'    => $border_radius_value,
				'bottomRight' => $border_radius_value,
				'bottomLeft'  => $border_radius_value,
			);
		} else {

			$radius = isset( $style['border']['radius'] ) ? $style['border']['radius'] : array();
		}

		// Spacing
		$padding = isset( $style['spacing']['padding'] ) ? $style['spacing']['padding'] : array();

		$padding_top    = isset( $padding['top'] ) ? $this->parse_preset_spacing( $padding['top'] ) : '';
		$padding_bottom = isset( $padding['bottom'] ) ? $this->parse_preset_spacing( $padding['bottom'] ) : '';
		$padding_left   = isset( $padding['left'] ) ? $this->parse_preset_spacing( $padding['left'] ) : '';
		$padding_right  = isset( $padding['right'] ) ? $this->parse_preset_spacing( $padding['right'] ) : '';

		// TYPOGRAPHY
		$typography = isset( $style['typography'] ) ? $style['typography'] : array();

		$font_style      = isset( $typography['fontStyle'] ) ? $typography['fontStyle'] : '';
		$font_weight     = isset( $typography['fontWeight'] ) ? $typography['fontWeight'] : '';
		$text_decoration = isset( $typography['textDecoration'] ) ? $typography['textDecoration'] : '';
		$letter_spacing  = isset( $typography['letterSpacing'] ) ? $typography['letterSpacing'] : '';
		$text_transform  = isset( $typography['textTransform'] ) ? $typography['textTransform'] : '';
		$font_size       = isset( $typography['fontSize'] ) ? $typography['fontSize'] : ( isset( $attr['fontSize'] ) ? $attr['fontSize'] : '' );

		// Build BUTTON inline style
		$button_style = 'width:100%;';

		// Style variations - default styles
		if ( strpos( $button_classes, 'is-style-fill' ) !== false ) {
			$button_style .= 'background:#000;color:#fff;';
		}

		if ( strpos( $button_classes, 'is-style-outline' ) !== false ) {
			$button_style .= 'background:transparent;border:1px solid #000;color:#000;';
		}

		// Apply custom colors
		if ( $text_color ) {
			$button_style .= 'color:' . $text_color . ';';
		}
		if ( $background ) {
			$button_style .= 'background:' . $background . ';';
		}
		if ( $border_color ) {
			$button_style .= 'border-color:' . $border_color . ';';
		}
		if ( $border_width && strpos( $button_classes, 'is-style-outline' ) !== false ) {
			$button_style .= 'border-width:' . $border_width . ';border-style:' . $border_style . ';';
		}

		// Padding
		if ( $padding_top ) {
			$button_style .= 'padding-top:' . $padding_top . ';';
		}
		if ( $padding_bottom ) {
			$button_style .= 'padding-bottom:' . $padding_bottom . ';';
		}
		if ( $padding_left ) {
			$button_style .= 'padding-left:' . $padding_left . ';';
		}
		if ( $padding_right ) {
			$button_style .= 'padding-right:' . $padding_right . ';';
		}

		// Border Radius
		if ( ! empty( $radius['topLeft'] ) ) {
			$button_style .= 'border-top-left-radius:' . $radius['topLeft'] . ';';
		}

		if ( ! empty( $radius['topRight'] ) ) {
			$button_style .= 'border-top-right-radius:' . $radius['topRight'] . ';';
		}

		if ( ! empty( $radius['bottomRight'] ) ) {
			$button_style .= 'border-bottom-right-radius:' . $radius['bottomRight'] . ';';
		}

		if ( ! empty( $radius['bottomLeft'] ) ) {
			$button_style .= 'border-bottom-left-radius:' . $radius['bottomLeft'] . ';';
		}

		// Typography
		if ( $font_style ) {
			$button_style .= 'font-style:' . $font_style . ';';
		}
		if ( $font_weight ) {
			$button_style .= 'font-weight:' . $font_weight . ';';
		}
		if ( $text_decoration ) {
			$button_style .= 'text-decoration:' . $text_decoration . ';';
		}
		if ( $letter_spacing ) {
			$button_style .= 'letter-spacing:' . $letter_spacing . ';';
		}
		if ( $text_transform ) {
			$button_style .= 'text-transform:' . $text_transform . ';';
		}
		if ( $font_size ) {
			if ( 'small' === $font_size ) {
				$button_style .= 'font-size:12px;';
			} elseif ( 'medium' === $font_size ) {
				$button_style .= 'font-size:14px;';
			} elseif ( 'large' === $font_size ) {
				$button_style .= 'font-size:16px;';
			} elseif ( 'x-large' === $font_size ) {
				$button_style .= 'font-size:18px;';
			} else {
				$button_style .= 'font-size:' . $font_size . ';';
			}
		}

		// Wrapper attributes
		$justify = isset( $attr['justifyContent'] ) ? $attr['justifyContent'] : 'flex-start';

		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class' => 'wp-block-buttons ' . $block_id,
				'style' => 'display:flex;flex-direction:row;justify-content:' . esc_attr( $justify ) . ';',
			)
		);

		$link_extra_attributes = '';

		if ( ! $is_editor ) {
			$link_extra_attributes = $attr['openInNewTab'] ? 'target="_blank"' : '';
		}

		// Build inline hover styles
		$inline_hover_styles = '';
		if ( $text_hover_color ) {
			$inline_hover_styles .= '.urm-' . $block_id . ':hover{color:' . esc_attr( $text_hover_color ) . ' !important;}';
		}
		if ( $text_hover_bg_color ) {
			$inline_hover_styles .= '.urm-' . $block_id . ':hover{background-color:' . esc_attr( $text_hover_bg_color ) . ' !important;}';
		}

		// FINAL HTML
		$html = '';

		// Add inline hover styles if needed
		if ( ! empty( $inline_hover_styles ) ) {
			$html .= '<style>' . $inline_hover_styles . '</style>';
		}

		$membership_id = isset( $attr['membershipId'] ) ? $attr['membershipId'] : '';
		$action_to_take = 'upgrade';

		$current_user_id     = get_current_user_id();
		$user_membership_ids = array();
		$members_repository      = new MembersRepository();
		$membership_repository      = new MembershipRepository();
		$membership_service      = new MembershipService();
		$current_plan = false;
		$button_text = ! empty( $attr['text'] ) ? esc_html__( sanitize_text_field( $attr['text'] ), 'user-registration' ) : __( 'Buy Now', 'user-registration' );

		if ( $current_user_id ) {
			$user_memberships            = $members_repository->get_member_membership_by_id( $current_user_id );
			$user_membership_ids         = array_filter(
				array_map(
					function ( $user_memberships ) {
						return $user_memberships['post_id'];
					},
					$user_memberships
				)
			);

			if ( in_array( $membership_id, $user_membership_ids ) ) {
				$current_plan = true;
				$button_text  = esc_html__( 'Current Plan', 'user-registration' );
			}
		}

		$membership_details = $membership_repository->get_single_membership_by_ID( $membership_id );

		$intended_action = $membership_service->fetch_intended_action( $action_to_take, $membership_details, $user_membership_ids );
		$thank_you_page_id   = ur_get_translated_page_id( get_option( 'user_registration_thank_you_page_id', false ) );

		$redirect_link_builder = array(
				'action'  => $intended_action,
				'membership_id' => $membership_id,
				'thank_you' => $thank_you_page_id,
			);
		$concatenator       = strpos( $page_url, '?' ) === false ? '?' : '&';
		$registration_page_url = $page_url . $concatenator . http_build_query(
			$redirect_link_builder
		);

		$html .= '<div ' . $wrapper_attributes . '>';
		$html .= '<div style="width:' . esc_attr( $attr['width'] ) . ';">';
		$html .= '<a class="buynow-link" href="' . esc_url( $registration_page_url ) . '" ' . $link_extra_attributes . ' style="text-decoration:none;">';
		$html .= '<button type="button" class="' . esc_attr( $button_classes ) . '" style="' . esc_attr( $button_style ) . '" ' . ( empty( $registration_page_url ) || $current_plan ? 'disabled' : '' ) . '>';
		$html .= '<span class="label">' . esc_html( $button_text ) . '</span>';
		$html .= '</button>';
		$html .= '</a>';
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}
}
