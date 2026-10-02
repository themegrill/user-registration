<?php
/**
 * User Registration Membership Shortcodes.
 *
 * @class    ShortCodes
 * @package  URMembership/ShortCodes
 */

namespace WPEverest\URMembership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ShortCodes Class
 */
class ShortCodes {
	/**
	 * Init Shortcodes.
	 */
	public static function init() {
		$shortcodes = array(
			//          'user_registration_membership_member_registration_form' => __CLASS__ . '::member_registration_form',
							'user_registration_groups' => __CLASS__ . '::membership_listing',
			'user_registration_membership_thank_you'   => __CLASS__ . '::thank_you',
			'user_registration_membership_listing'     => __CLASS__ . '::membership_listing',
		);

		foreach ( $shortcodes as $shortcode => $function ) {
			add_shortcode(
				apply_filters( "{$shortcode}_shortcode_tag", $shortcode ),
				self::get_shortcode_callback( $function, $shortcode )
			);
		}
	}

	private static function get_shortcode_callback( $function, $shortcode ) {
		return function ( $atts = array() ) use ( $function, $shortcode ) {
			return call_user_func( $function, $atts, $shortcode );
		};
	}

	/**
	 * Shortcode Wrapper.
	 *
	 * @param string[] $function Callback function.
	 * @param array $attributes (default: array()) Extra attributes.
	 * @param string $shortcode (default: ') Shortcode itself.
	 *
	 * @return string
	 */
	public static function shortcode_wrapper(
		$function = array(),
		$attributes = array(),
		$shortcode = ''
	) {
		// Membership listings and thank you screens vary with the visitor's own plans.
		\UR_Cache_Helper::disable_page_cache( 'membership' );

		ob_start();
		call_user_func( $function, $attributes, $shortcode );

		return ob_get_clean();
	}

	/**
	 * Shortcode initialization for member registration form.
	 *
	 * @param mixed $attributes shortcode attributes.
	 *
	 * @return string
	 */
	public static function member_registration_form( $attributes ) {
		//for blocks refer to register_membership_admin_scripts function in Admin.php to load scripts and styles
		do_action( 'wp_enqueue_membership_scripts' );
		wp_enqueue_script( 'user-registration-membership-frontend-script' );
		wp_enqueue_script( 'ur-snackbar' );
		wp_enqueue_style( 'ur-snackbar' );

		return self::shortcode_wrapper(
			array(
				'WPEverest\URMembership\Admin\Views\Shortcode\MemberRegistrationFormShortcode',
				'display_form',
			),
			$attributes
		);
	}

	/**
	 * Shortcode initialization for membership listing.
	 *
	 * @param mixed $attributes shortcode attributes.
	 * @param string $shortcode shortcode itself.
	 *
	 * @return string
	 */
	public static function membership_listing( $attributes, $shortcode ) {
		do_action( 'wp_enqueue_membership_scripts' );
		wp_enqueue_script( 'user-registration-membership-frontend-script' );

		return self::shortcode_wrapper(
			array(
				'WPEverest\URMembership\Admin\Views\Shortcode\MembershipListingShortcode',
				'render_template',
			),
			$attributes,
			$shortcode
		);
	}

	/**
	 * Shortcode initialization for thank you page.
	 *
	 * @param mixed $attributes shortcode attributes.
	 * @param mixed $content shortcode itself.
	 * @param mixed $shortcode shortcode itself.
	 *
	 * @return string
	 */
	public static function thank_you( $attributes, $shortcode ) {
		do_action( 'wp_enqueue_membership_scripts' );
		wp_enqueue_script( 'user-registration-membership-frontend-script' );

		return self::shortcode_wrapper(
			array(
				'WPEverest\URMembership\Admin\Views\Shortcode\ThankYouShortcode',
				'render_template',
			),
			$attributes,
			$shortcode
		);
	}
}
