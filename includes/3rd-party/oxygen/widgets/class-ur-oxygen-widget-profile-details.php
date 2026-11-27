<?php
require_once 'class-ur-oxygen-widgets.php';

class UR_OXYGEN_WIDGET_PROFILE_DETAILS extends UR_OXYGEN_WIDGET {

	/**
	 * Constructor for the Oxygen Form Widget.
	 *
	 * @since 3.3.5
	 */
	public function __construct() {
		$this->name     = __( 'View Profile Details', 'user-registration' );
		$this->slug     = 'ur-profile-details';
		$this->icon     = $this->get_icon_svg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" size="24" class="css-f0g03s"><path fill="#FFFFFF" fill-rule="evenodd" d="M14.911 8.874c0 .828-.31 1.583-.822 2.156a4.531 4.531 0 0 1 1.734 2.13 4.5 4.5 0 0 1 .314 1.736l-1.818-.032a2.704 2.704 0 0 0-2.523-2.747 2.703 2.703 0 0 0-2.523 2.747l-1.818.032a4.522 4.522 0 0 1 1.898-3.764 3.238 3.238 0 1 1 5.558-2.258Zm-4.657 0a1.42 1.42 0 1 1 1.44 1.42h-.02a1.42 1.42 0 0 1-1.42-1.42Z" clip-rule="evenodd"></path><path fill="#FFFFFF" fill-rule="evenodd" d="M9.273 16.546a.91.91 0 1 0 0 1.818h5.3a.91.91 0 1 0 0-1.819h-5.3Z" clip-rule="evenodd"></path><path fill="#FFFFFF" fill-rule="evenodd" d="M4.727 2A2.727 2.727 0 0 0 2 4.727v14.546A2.727 2.727 0 0 0 4.727 22h14.546A2.727 2.727 0 0 0 22 19.273V4.727A2.727 2.727 0 0 0 19.273 2H4.727Zm-.909 2.727a.91.91 0 0 1 .91-.909h14.545a.91.91 0 0 1 .909.91v14.545a.91.91 0 0 1-.91.909H4.728a.91.91 0 0 1-.909-.91V4.728Z" clip-rule="evenodd"></path></svg>' );
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
				'slug'    => 'ur_oxygen_profiledetails',
				'value'   => apply_filters(
					'ur_oxygen_widget_myaccount_options',
					array(
						'user_registration_view_profile_details' => __( 'Profile Details', 'user-registration' ),
					)
				),
				'default' => 'user_registration_view_profile_details',
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

		$shortcode = isset( $options['ur_oxygen_profiledetails'] ) ? $options['ur_oxygen_profiledetails'] : '';
		$this->add_form( $shortcode );
	}

	/**
	 * Add the form.
	 *
	 * @since 3.3.5
	 */
	public function add_form( $shortcode ) {
		if ( ! empty( $shortcode ) ) {
			$shortcode = '[' . $shortcode . ']';
			echo ur_escape_shortcodes( do_shortcode( $shortcode ) );
		} else {
			echo __( 'Please select a form.', 'user-registration' );
		}
	}
}
