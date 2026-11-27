<?php

/**
 * User Registration Login Form for Elementor.
 *
 * @package UserRegistration\Class
 */

use Elementor\Plugin;
use Elementor\Widget_Base;
use Elementor\Controls_Manager;

/**
 * User Registration Login Forms Widget for Elementor.
 *
 * @since 3.2.2
 */
class UR_Elementor_Widget_Login extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve shortcode widget name.
	 *
	 * @since 3.2.2
	 *
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'user-registration-login';
	}
	/**
	 * Get widget title.
	 *
	 * Retrieve shortcode widget title.
	 *
	 * @since 3.2.2
	 *
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'Login Form', 'user-registration' );
	}
	/**
	 * Get widget icon.
	 *
	 * Retrieve shortcode widget icon.
	 *
	 * @since 3.2.2
	 *
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'ur-icon-login';
	}
	/**
	 * Get widget categories.
	 *
	 * @since 3.2.2
	 *
	 * @return array Widget categories.
	 */
	public function get_categories() {

		return array(
			'user-registration',
		);
	}
	/**
	 * Get widget keywords.
	 *
	 * Retrieve the list of keywords the widget belongs to.
	 *
	 * @since 3.2.2
	 *
	 * @return array Widget keywords.
	 */
	public function get_keywords() {
		return array( 'form', 'forms', 'user-registration', 'login form', 'user-registration-login', 'registration form' );
	}
	/**
	 * Register controls.
	 *
	 * @since 3.2.2
	 */
	protected function register_controls() {
	 do_action( 'user_registration_elementor_login_style', $this );
	}
	/**
	 * Retrieve the shortcode.
	 *
	 * @since 3.2.2
	 */
	private function get_shortcode() {

		$settings = $this->get_settings_for_display();

		$shortcode =  '[user_registration_login]' ;
		$shortcode = sprintf( apply_filters( 'user_registration_elementor_shortcode_login_form', $shortcode, $settings ) );
		return $shortcode;
	}
	public function get_style_depends() {
		return array( 'user-registration-general' );
	}

	/**
	 * Render widget output.
	 *
	 * @since 3.2.2
	 */
	protected function render() {
		echo ur_escape_shortcodes( do_shortcode( $this->get_shortcode() ) );
	}
	/**
	 * Retrieve the  available  forms.
	 *
	 * @since 3.2.2
	 */
	public function get_forms() {
		$user_registration_forms = array();

		if ( empty( $user_registration_forms ) ) {
			$user_registration_forms['ur_login_form'] = esc_html__( 'Login Form', 'user-registration' );
			return $user_registration_forms;
		}
	}
}
