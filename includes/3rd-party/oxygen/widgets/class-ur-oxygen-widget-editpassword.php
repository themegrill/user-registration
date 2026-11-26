<?php
require_once 'class-ur-oxygen-widgets.php';

class UR_OXYGEN_WIDGET_EDITPASSWORD extends UR_OXYGEN_WIDGET {

	/**
	 * Constructor for the Oxygen Form Widget.
	 *
	 * @since 3.3.5
	 */
	public function __construct() {
		$this->name     = __( 'Edit Password', 'user-registration' );
		$this->slug     = 'ur-edit-password';
		$this->icon     = $this->get_icon_svg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" size="24" class="css-f0g03s"><path fill="#FFFFFF" fill-rule="evenodd" d="M5.636 4a.91.91 0 0 0 0 1.818h.91a1.818 1.818 0 0 1 1.818 1.818v3.527a.993.993 0 0 0-.367.237l-.384.383-.383-.383c-.289-.29-.681-.365-.877-.17-.195.196-.12.588.17.877l.383.383-.383.384c-.29.288-.365.68-.17.876.196.195.588.12.877-.169l.383-.384.384.384c.112.112.24.193.367.237v2.91a1.818 1.818 0 0 1-1.819 1.817h-.909a.91.91 0 1 0 0 1.819h.91a3.637 3.637 0 0 0 2.727-1.232A3.637 3.637 0 0 0 12 20.364h.91a.91.91 0 1 0 0-1.817H12a1.818 1.818 0 0 1-1.818-1.819v-9.09A1.818 1.818 0 0 1 12 5.817h.91a.91.91 0 1 0 0-1.818H12A3.637 3.637 0 0 0 9.273 5.23 3.635 3.635 0 0 0 6.545 4h-.909Zm2.728 8.534v-.087l-.044.043.044.043Zm-3.637-3.08a.91.91 0 0 0-.909.91V14a.91.91 0 0 0 .91.91h.908a.91.91 0 1 1 0 1.817h-.909A2.728 2.728 0 0 1 2 14v-3.636a2.727 2.727 0 0 1 2.727-2.728h.91a.91.91 0 0 1 0 1.819h-.91Zm8.182-1.818a.91.91 0 1 0 0 1.819h6.364a.91.91 0 0 1 .909.909V14a.91.91 0 0 1-.91.91H12.91a.91.91 0 1 0 0 1.817h6.364A2.728 2.728 0 0 0 22 14v-3.636a2.727 2.727 0 0 0-2.727-2.728h-6.364Zm.795 5.238-.384-.384.384-.383c.289-.289.364-.681.17-.877-.196-.195-.588-.119-.877.17l-.384.383-.383-.383c-.289-.29-.681-.365-.877-.17-.195.196-.12.588.17.877l.383.383-.383.384c-.29.288-.365.68-.17.876.196.195.588.12.877-.169l.383-.384.384.384c.288.289.68.364.876.169.195-.195.12-.588-.17-.876Zm3.13-1.475.383.384.384-.383c.289-.289.68-.365.876-.17.195.196.12.588-.169.877l-.384.383.384.384c.288.288.364.68.169.876-.195.195-.588.12-.876-.17l-.384-.383-.383.384c-.289.289-.681.364-.877.17-.195-.196-.12-.588.17-.877l.383-.384-.384-.383c-.288-.289-.364-.681-.169-.877.196-.195.588-.12.877.17Z" clip-rule="evenodd"></path></svg>' );
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
				'slug'    => 'ur_oxygen_editpassword',
				'value'   => apply_filters(
					'ur_oxygen_widget_edit_password_options',
					array(
						'user_registration_edit_password' => __( 'Edit Password', 'user-registration' ),
					)
				),
				'default' => 'user_registration_edit_password',
				'css'     => false,
			)
		);

		$templates_control->rebuildElementOnChange();
		$this->form_container_style_controls();
		$this->form_input_labels_style();
		$this->submit_btn_style( '.user-registration-Button' );
	}

	/**
	 * Render the widget.
	 *
	 * @since 3.3.5
	 */
	public function render( $options, $defaults, $content ) {

		$shortcode = isset( $options['ur_oxygen_editpassword'] ) ? $options['ur_oxygen_editpassword'] : '';
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
