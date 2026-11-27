<?php
/**
 * User Registration Edit Profile Form for Elementor.
 *
 * @package UserRegistration\Class
 * @since 3.2.2
 */

use Elementor\Plugin;
use Elementor\Widget_Base;
use Elementor\Controls_Manager;

/**
 * User Registration Edit Profile Forms Widget for Elementor.
 *
 */
class UR_Elementor_Widget_Edit_Profile extends Widget_Base {
	/**
	 * Get widget name.
	 *
	 * Retrieve shortcode widget name.
	 *
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'user-registration-edit-profile';
	}
	/**
	 * Get widget title.
	 *
	 * Retrieve shortcode widget title.
	 *
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'Edit Profile', 'user-registration' );
	}
	/**
	 * Get widget icon.
	 *
	 * Retrieve shortcode widget icon.
	 *
	 *
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'ur-icon-edit-profile';
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
	 *
	 * @return array Widget keywords.
	 */
	public function get_keywords() {
		return array( 'form', 'forms', 'user-registration', 'login form', 'user-registration-edit-profile', 'edit profile' );
	}
	/**
	 * Register controls.
	 *
	 */
	protected function register_controls() {
		do_action( 'user_registration_elementor_edit_profile_style', $this );

	}
	/**
	 * Retrieve the shortcode.
	 *
	 */
	private function get_shortcode() {

		$settings = $this->get_settings_for_display();

		$shortcode =  '[user_registration_edit_profile]' ;
		$shortcode = sprintf( apply_filters( 'user_registration_elementor_shortcode_edit_profile', $shortcode, $settings ) );
		return $shortcode;
	}
	public function get_style_depends() {
		return array( 'user-registration-general' );
	}

	/**
	 * Render widget output.
	 *
	 */
	protected function render() {
		echo ur_escape_shortcodes( do_shortcode( $this->get_shortcode() ) );
	}
	/**
	 * Retrieve the  available  forms.
	 *
	 */
	public function get_forms() {
		$user_registration_forms = array();

		if ( empty( $user_registration_forms ) ) {
			$user_registration_forms['ur_edit_profile'] = esc_html__( 'Default Form', 'user-registration' );
			return $user_registration_forms;
		}
	}
}
