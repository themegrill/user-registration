<?php
/**
 * UR_Setting_User_login Class
 *
 * @package  UserRegistration/Form/Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * User Login field setting class.
 */
class UR_Setting_User_Login extends UR_Field_Settings {

	/**
	 * Construct.
	 */
	public function __construct() {
		$this->field_id = 'user_login_advance_setting';
	}

	/**
	 * Output the field.
	 *
	 * @param array $field_data Field Data.
	 */
	public function output( $field_data = array() ) {

		$this->field_data = $field_data;
		$this->register_fields();
		$field_html = $this->fields_html;

		return $field_html;
	}

	/**
	 * Register fields.
	 */
	public function register_fields() {
		$fields = array(

			'custom_class'       => array(
				'label'       => __( 'Custom Class', 'user-registration' ),
				'data-id'     => $this->field_id . '_custom_class',
				'name'        => $this->field_id . '[custom_class]',
				'class'       => $this->default_class . ' ur-settings-custom-class',
				'type'        => 'text',
				'required'    => false,
				'default'     => '',
				'placeholder' => __( 'Custom Class', 'user-registration' ),
				'tip'         => __( 'Add a CSS class for custom styling.', 'user-registration' ),
			),
			'username_length'    => array(
				'label'       => __( 'Limit Username Length', 'user-registration' ),
				'data-id'     => $this->field_id . '_username_length',
				'name'        => $this->field_id . '[username_length]',
				'class'       => $this->default_class . ' ur-settings-min',
				'type'        => 'number',
				'required'    => false,
				'default'     => '',
				'placeholder' => __( 'Maximum Value', 'user-registration' ),
				'tip'         => __( 'Enter maximum number of characters allowed in the username.', 'user-registration' ),
			),
			'username_character' => array(
				'label'       => __( 'Allow Special Characters', 'user-registration' ),
				'data-id'     => $this->field_id . '_username_character',
				'name'        => $this->field_id . '[username_character]',
				'class'       => $this->default_class . ' ur-settings-character',
				'type'        => 'toggle',
				'required'    => false,
				'default'     => 'yes',
				'placeholder' => '',
				'tip'         => __( 'Check this option if you want to allow special characters (like @, #, $, etc.) in the username.', 'user-registration' ),
			),
		);

		/**
		 * Filter to modify the user login custom advance settings.
		 *
		 * @param string $fields Advance Settings Fields.
		 * @param int field_id Field ID.
		 * @param class default_class Default Class.
		 *
		 * @return string $fields.
		 */
		$fields = apply_filters( 'user_login_custom_advance_settings', $fields, $this->field_id, $this->default_class );
		$this->render_html( $fields );
	}
}

return new UR_Setting_User_login();
