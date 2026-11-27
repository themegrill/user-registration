<?php
require_once 'class-ur-oxygen-widgets.php';

class UR_OXYGEN_WIDGET_EDITPROFILE extends UR_OXYGEN_WIDGET {

	/**
	 * Constructor for the Oxygen Form Widget.
	 *
	 * @since 3.3.5
	 */
	public function __construct() {
		$this->name     = __( 'Edit Profile', 'user-registration' );
		$this->slug     = 'ur-editprofile';
		$this->icon     = $this->get_icon_svg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" size="24" class="css-f0g03s"><path fill="#FFFFFF" fill-rule="evenodd" d="M10.295 3.843a3.687 3.687 0 1 0 0 7.374 3.687 3.687 0 0 0 0-7.374ZM4.765 7.53a5.53 5.53 0 1 1 8.618 4.588.922.922 0 0 1-1.125 1.247 6.452 6.452 0 0 0-8.415 6.146.922.922 0 0 1-1.843 0 8.295 8.295 0 0 1 4.94-7.585A5.521 5.521 0 0 1 4.764 7.53Zm11.06 9.216a1.843 1.843 0 1 1 3.687 0 1.843 1.843 0 0 1-3.687 0Zm-1.812-.482a3.716 3.716 0 0 0 0 .965l-.13.057a.922.922 0 0 0 .75 1.685l.071-.032c.218.294.478.554.772.772l-.033.072a.922.922 0 1 0 1.685.749l.058-.13a3.712 3.712 0 0 0 .965 0l.058.13a.922.922 0 0 0 1.684-.749l-.032-.073c.293-.217.554-.477.771-.771l.072.032a.922.922 0 0 0 .748-1.685l-.129-.057a3.73 3.73 0 0 0 0-.965l.13-.058a.922.922 0 1 0-.75-1.684l-.071.032a3.708 3.708 0 0 0-.77-.771l.031-.072a.922.922 0 0 0-1.684-.748l-.058.128a3.72 3.72 0 0 0-.966 0l-.057-.128a.922.922 0 1 0-1.684.748l.031.072a3.707 3.707 0 0 0-.77.77l-.073-.031a.922.922 0 0 0-.748 1.684l.129.058Z" clip-rule="evenodd"></path></svg>' );
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
				'slug'    => 'ur_oxygen_editprofile',
				'value'   => apply_filters(
					'ur_oxygen_widget_editprofile_options',
					array(
						'user_registration_edit_profile' => __( 'Edit Profile', 'user-registration' ),
					)
				),
				'default' => 'user_registration_edit_profile',
				'css'     => false,
			)
		);

		$templates_control->rebuildElementOnChange();
		$this->form_container_style_controls();
		$this->form_input_labels_style();
		$this->submit_btn_style( '.user-registration-submit-Button' );
	}

	/**
	 * Render the widget.
	 *
	 * @since 3.3.5
	 */
	public function render( $options, $defaults, $content ) {

		$shortcode = isset( $options['ur_oxygen_editprofile'] ) ? $options['ur_oxygen_editprofile'] : '';
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
