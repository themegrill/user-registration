<?php
require_once 'class-ur-oxygen-widgets.php';

class UR_OXYGEN_WIDGET_REGISTRATION extends UR_OXYGEN_WIDGET {

	/**
	 * Constructor for the Oxygen Form Widget.
	 *
	 * @since 3.3.5
	 */
	public function __construct() {
		$this->name     = __( 'Registration Form ', 'user-registration' );
		$this->slug     = 'ur-registration';
		$this->icon     = $this->get_icon_svg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" size="24" class="css-f0g03s"><path fill="#FFFFFF" fill-rule="evenodd" d="M9.455 2a1.818 1.818 0 0 0-1.819 1.818h-.909A2.727 2.727 0 0 0 4 6.545v12.728A2.727 2.727 0 0 0 6.727 22h10.91a2.727 2.727 0 0 0 2.727-2.727V6.545a2.727 2.727 0 0 0-2.728-2.727h-.909A1.818 1.818 0 0 0 14.91 2H9.455Zm7.272 3.636a1.818 1.818 0 0 1-1.818 1.819H9.455a1.818 1.818 0 0 1-1.819-1.819h-.909a.91.91 0 0 0-.909.91v12.727a.91.91 0 0 0 .91.909h10.908a.91.91 0 0 0 .91-.91V6.546a.91.91 0 0 0-.91-.909h-.909Zm-7.272-.909v.91h5.454v-1.82H9.455v.91Zm-1.819 6.364a.91.91 0 0 1 .91-.91h7.272a.91.91 0 1 1 0 1.819H8.545a.91.91 0 0 1-.909-.91Zm.91 3.636a.91.91 0 1 0 0 1.819h7.272a.91.91 0 0 0 0-1.819H8.545Z" clip-rule="evenodd"></path></svg>' );
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
				'slug'    => 'ur_registration_form_id',
				'value'   => $this->get_forms( 'registration' ),
				'default' => '',
				'css'     => false,
			)
		);
		// echo '<style>
		// .oxygen-add-section-element[data-searchid="registration_form"] *:not(img) {
		// line-height: 1.5; /* Adjust this value as needed */
		// }
		// </style>';
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
		$form_id = isset( $options['ur_registration_form_id'] ) ? $options['ur_registration_form_id'] : '';
		$this->add_form( $form_id );
	}

	/**
	 * Add the form.
	 *
	 * @since 3.3.5
	 */
	public function add_form( $form_id ) {
		if ( empty( $form_id ) ) {
			return;
		}
		$shortcode = '[user_registration_form id="' . esc_attr( $form_id ) . '"]';
		echo ur_escape_shortcodes( do_shortcode( $shortcode ) );
	}
}
