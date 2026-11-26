<?php
	require_once 'class-ur-oxygen-widgets.php';

class UR_OXYGEN_WIDGET_LOGIN extends UR_OXYGEN_WIDGET {

	/**
	 * Constructor for the Oxygen Form Widget.
	 *
	 * @since 3.3.5
	 */
	public function __construct() {
		$this->name     = __( 'Login Form ', 'user-registration' );
		$this->slug     = 'ur-login';
		$this->icon     = $this->get_icon_svg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" size="24" class="css-f0g03s"><path fill="#FFFFFF" fill-rule="evenodd" d="M8.545 9.273a.91.91 0 1 0 0 1.818h7.273a.91.91 0 1 0 0-1.818H8.545Zm-.909 4.545a.91.91 0 0 1 .91-.909h7.272a.91.91 0 1 1 0 1.818H8.545a.91.91 0 0 1-.909-.909Zm.909 2.728a.91.91 0 1 0 0 1.818h1.819a.91.91 0 1 0 0-1.819H8.545Z" clip-rule="evenodd"></path><path fill="#FFFFFF" fill-rule="evenodd" d="M7.636 3.818C7.636 2.814 8.45 2 9.455 2h5.454c1.004 0 1.818.814 1.818 1.818h.91a2.727 2.727 0 0 1 2.727 2.727v12.728A2.727 2.727 0 0 1 17.636 22H6.727A2.727 2.727 0 0 1 4 19.273V6.545a2.727 2.727 0 0 1 2.727-2.727h.91Zm7.273 3.637a1.818 1.818 0 0 0 1.818-1.819h.91a.91.91 0 0 1 .909.91v12.727a.91.91 0 0 1-.91.909H6.727a.91.91 0 0 1-.909-.91V6.546a.91.91 0 0 1 .91-.909h.908c0 1.005.814 1.819 1.819 1.819h5.454ZM9.455 5.636V3.818h5.454v1.818H9.455Z" clip-rule="evenodd"></path></svg>' );
		$this->priority = 1;
		parent::__construct();
	}

	/**
	 * Register widget controls.
	 *
	 * @since 3.3.5
	 */
	public function controls() {
		$templates_control = $this->addOptionControl(
			array(
				'type'    => 'dropdown',
				'name'    => __( 'Select a Form', 'user-registration' ),
				'slug'    => 'ur_oxygen_login',
				'value'   => apply_filters(
					'ur_oxygen_widget_login_options',
					array(
						'user_registration_login' => __( 'Login Form', 'user-registration' ),
					)
				),
				'default' => 'user_registration_login',
				'css'     => false,
			)
		);

		$templates_control->rebuildElementOnChange();
		$this->form_container_style_controls();
		$this->form_input_labels_style();
		$this->submit_btn_style();
	}

	/**
	 * Render the widget.
	 *
	 * @since 3.3.5
	 */
	public function render( $options, $defaults, $content ) {

		$login_shortcode = isset( $options['ur_oxygen_login'] ) ? $options['ur_oxygen_login'] : '';
		$this->add_form( $login_shortcode );
	}

	/**
	 * Add the form.
	 *
	 * @since 3.3.5
	 */
	public function add_form( $login_shortcode ) {
		if ( ! empty( $login_shortcode ) ) {
			$shortcode = '[' . $login_shortcode . ']';
			echo ur_escape_shortcodes( do_shortcode( $shortcode ) );
		} else {
			echo __( 'Please select a form.', 'user-registration' );
		}
	}
}
