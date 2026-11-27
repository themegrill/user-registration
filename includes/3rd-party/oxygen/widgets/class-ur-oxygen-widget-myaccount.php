<?php
require_once 'class-ur-oxygen-widgets.php';

class UR_OXYGEN_WIDGET_MYACCOUNT extends UR_OXYGEN_WIDGET {

	/**
	 * Constructor for the Oxygen Form Widget.
	 *
	 * @since 3.3.5
	 */
	public function __construct() {
		$this->name     = __( 'Myaccount', 'user-registration' );
		$this->slug     = 'ur-myaccount';
		$this->icon     = $this->get_icon_svg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" size="24" class="css-f0g03s"><path fill="#FFFFFF" fill-rule="evenodd" d="M4 5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-.072a6.999 6.999 0 0 0-3.678-5.2 5 5 0 1 0-6.5 0A6.997 6.997 0 0 0 5.072 20H5a1 1 0 0 1-1-1V5Zm2 17H5a3 3 0 0 1-3-3V5a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v14a3 3 0 0 1-3 3H6Zm10.899-2H7.101a5 5 0 0 1 9.798 0ZM12 8a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z" clip-rule="evenodd"></path></svg>' );
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
				'slug'    => 'ur_oxygen_myaccount',
				'value'   => apply_filters(
					'ur_oxygen_widget_myaccount_options',
					array(
						'user_registration_my_account' => __( 'My Account', 'user-registration' ),
					)
				),
				'default' => 'user_registration_my_account',
				'css'     => false,
			)
		);

		$templates_control->rebuildElementOnChange();
		$this->form_container_style_controls();
		$this->form_input_labels_style();
	}

	/**
	 * Render the widget.
	 *
	 * @since 3.3.5
	 */
	public function render( $options, $defaults, $content ) {

		$shortcode = isset( $options['ur_oxygen_myaccount'] ) ? $options['ur_oxygen_myaccount'] : '';
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
