<?php

/**
 * User Registration MyAccount for Elementor.
 *
 * @package UserRegistration\Class
 * @since 3.2.2
 */

use Elementor\Plugin;
use Elementor\Widget_Base;
use Elementor\Controls_Manager;

/**
 * User Registration MyAccount Widget for Elementor.
 */
class UR_Elementor_Widget_MyAccount extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve shortcode widget name.
	 *
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'user-registration-myaccount';
	}
	/**
	 * Get widget title.
	 *
	 * Retrieve shortcode widget title.
	 *
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'My Account', 'user-registration' );
	}
	/**
	 * Get widget icon.
	 *
	 * Retrieve shortcode widget icon.
	 *
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'ur-icon-myaccount';
	}
	/**
	 * Get widget categories.
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
	 * @return array Widget keywords.
	 */
	public function get_keywords() {
		return array( 'form', 'forms', 'user-registration', 'my-account', 'user-registration-login', 'my account' );
	}
	/**
	 * Register controls.
	 */
	protected function register_controls() {
		do_action( 'user_registration_elementor_myaccount_style', $this );

	}
	/**
	 * Retrieve the shortcode.
	 */
	private function get_shortcode() {

		$settings = $this->get_settings_for_display();
		if ( ! is_user_logged_in() ) {
			return sprintf( '[user_registration_login]' );
		}

		$shortcode = '[user_registration_my_account]';
		$shortcode = sprintf( apply_filters( 'user_registration_elementor_shortcode_my_account', $shortcode, $settings ) );
		return $shortcode;
	}
	public function get_style_depends() {
		return array( 'user-registration-general' );
	}

	/**
	 * Render widget output.
	 */
	protected function render() {

		// To preview in builder editor.
		if ( Elementor\Plugin::$instance->editor->is_edit_mode()  ) {
			echo '<div id="user-registration" class= "user-registration horizontal">';
			ur_get_template( 'myaccount/my-account.php' );
			echo '</div>';
		} else {
			echo ur_escape_shortcodes( do_shortcode( $this->get_shortcode() ) );
		}
	}
	/**
	 * Retrieve the  available  forms.
	 */
	public function get_forms() {
		$user_registration_forms = array();

		if ( empty( $user_registration_forms ) ) {
			$user_registration_forms['ur_my_account'] = esc_html__( 'Default Form', 'user-registration' );
			return $user_registration_forms;
		}
	}
}
