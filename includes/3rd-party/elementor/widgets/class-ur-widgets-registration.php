<?php
/**
 * User Registration Form for Elementor.
 *
 * @package UserRegistration\Class
 * @since 3.2.2
 */

use Elementor\Plugin;
use Elementor\Widget_Base;
use Elementor\Controls_Manager;

/**
 * User Registration Forms Widget for Elementor.
 */
class UR_Elementor_Widget_Registration extends Widget_Base {
	/**
	 * Get widget name.
	 *
	 * Retrieve shortcode widget name.
	 *
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'user-registration-form';
	}
	/**
	 * Get widget title.
	 *
	 * Retrieve shortcode widget title.
	 *
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'Registration Form', 'user-registration' );
	}
	/**
	 * Get widget icon.
	 *
	 * Retrieve shortcode widget icon.
	 *
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'form';
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
		return array( 'form', 'forms', 'user-registration', 'registration form', 'userregistration', 'userregistrations' );
	}
	/**
	 * Register controls.
	 */
	protected function register_controls() { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		$this->start_controls_section(
			'ur_elementor_registration_form',
			array(
				'label' => esc_html__( 'Form', 'user-registration' ),
			)
		);

		$forms = $this->get_forms();

		$this->add_control(
			'user_registration_form',
			array(
				'label'   => esc_html__( 'Select Form', 'user-registration' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $forms,
			)
		);
		$this->end_controls_section();

		do_action( 'user_registration_elementor_style', $this );
	}
	/**
	 * Retrieve the shortcode.
	 */
	private function get_shortcode() {

		$settings = $this->get_settings_for_display();
		if ( ! $settings['user_registration_form'] ) {
			return '<p>' . __( 'Please select a User Registration & Membership Forms.', 'user-registration' ) . '</p>';
		}

		$attributes = array(
			'id' => $settings['user_registration_form'],
		);

		$this->add_render_attribute( 'shortcode', $attributes );
		$shortcode   = array();
		$shortcode[] = sprintf( '[user_registration_form %s]', $this->get_render_attribute_string( 'shortcode' ) );
		$shortcode   = implode( '', $shortcode );
		$shortcode   = sprintf( apply_filters( 'user_registration_elementor_shortcode_registration_form', $shortcode, $settings ) );
		return $shortcode;
	}
	public function get_style_depends() {
		return array( 'user-registration-general' );
	}

	/**
	 * Render widget output.
	 */
	protected function render() {
		echo ur_escape_shortcodes( do_shortcode( $this->get_shortcode() ) );
	}
	/**
	 * Retrieve the  available UR forms.
	 */
	public function get_forms() {
		$user_registration_forms = array();

		if ( empty( $user_registration_forms ) ) {
			$ur_forms = ur_get_all_user_registration_form();
			if ( ! empty( $ur_forms ) ) {

				foreach ( $ur_forms as $form_value => $form_name ) {
					$user_registration_forms[ $form_value ] = $form_name;
				}
			} else {
				$user_registration_forms[0] = esc_html__( 'You have not created a form, Please Create a form first', 'user-registration' );
			}

			return $user_registration_forms;
		}
	}
}
